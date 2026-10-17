<?php

declare(strict_types=1);

/**
 * Endpoint HTTP do receptor de webhooks BladeCP→WHMCS. Arquivo acessado
 * diretamente, fora do roteamento do WHMCS:
 *
 *   POST https://<whmcs>/modules/addons/bladecp/webhook.php
 *   Header: BladeCP-Signature: t=<unix>,v1=<hmac-sha256("<t>.<body>", secret)>
 *   Header: X-Webhook-Id: <uuid do evento>   (idempotência)
 *   Body:   { "id", "type": "provision.suspended", "created",
 *             "data": { "provisionUuid": "...", "status": "suspended" } }
 *           ou { "id", "type": "vibe.account.suspended", "created",
 *             "data": { "accountId": "...", "status": "suspended",
 *                       "resourceVersion": 2 } }
 *
 * A LÓGICA mora em `WebhookReceiver` (puro + testado) e `WebhookConfig`
 * (segredo + registro); aqui é só o transporte: bootstrap do WHMCS,
 * verificação de assinatura (Stripe-style t/v1 + janela anti-replay),
 * dedupe por X-Webhook-Id, parse e reflexo de status. Tudo fail-soft.
 *
 * Respostas:
 *  - 2xx em sucesso (200 aplicado, 202 recebido-mas-ignorado/duplicado)
 *    para a plataforma não repetir a entrega;
 *  - 401 só em assinatura inválida; 400 só em payload inválido;
 *  - 503 enquanto não há segredo configurado (conecte os webhooks no
 *    addon admin OU defina BLADECP_WEBHOOK_SECRET) → a entrega será repetida.
 *
 * O segredo e o corpo cru NUNCA são logados (o scrub do Logger cobre
 * `(bcp|vh)_`; aqui só logamos type/uuid/resultado, jamais o secret).
 */

use WHMCS\Module\Addon\Bladecp\WebhookConfig;
use WHMCS\Module\Addon\Bladecp\WebhookEventStore;
use WHMCS\Module\Addon\Bladecp\WebhookReceiver;

/** Resposta JSON + status; encerra. Nunca vaza segredo/assinatura. */
$respond = static function (int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};

// Só POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 262144) {
    $respond(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
$mediaType = trim((string) strtok($contentType, ';'));
if ($mediaType !== 'application/json') {
    $respond(415, ['ok' => false, 'error' => 'unsupported_media_type']);
}

// Bootstrap do WHMCS (raiz = 3 níveis acima de modules/addons/bladecp).
// `WHMCS` precisa estar definido p/ as classes do módulo carregarem.
$initPath = dirname(__DIR__, 3) . '/init.php';
if (is_file($initPath)) {
    require_once $initPath;
}
if (!defined('WHMCS')) {
    // Fora do runtime WHMCS: não dá pra refletir status. Fail-soft.
    $respond(503, ['ok' => false, 'error' => 'whmcs_unavailable']);
}

require_once __DIR__ . '/../../servers/bladecp/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerAddonAutoloaders(
    __DIR__,
    __DIR__ . '/../../servers/bladecp'
);

// O receiver é vinculado ao servidor que registrou o endpoint. URLs legadas
// sem server_id só funcionam quando a configuração administrativa resolve um
// único servidor, jamais pelo primeiro registro encontrado no banco.
$queryServerId = trim((string) ($_GET['server_id'] ?? ''));
$serverId = preg_match('/^[1-9][0-9]*$/', $queryServerId) === 1
    ? (int) $queryServerId
    : 0;
if ($serverId <= 0 && $queryServerId === '' && method_exists(WebhookConfig::class, 'managementServerId')) {
    $serverId = max(0, (int) WebhookConfig::managementServerId());
}
if ($serverId <= 0 || !method_exists(WebhookConfig::class, 'getSecret')) {
    $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
}

// O segredo é resolvido para o servidor candidato antes da assinatura. A
// elegibilidade do registro WHMCS é conferida somente depois da assinatura.
$secret = WebhookConfig::getSecret($serverId);
if ($secret === '') {
    $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
}

$input = @fopen('php://input', 'rb');
$payload = $input === false ? '' : (string) stream_get_contents($input, 262145);
if (is_resource($input)) {
    fclose($input);
}
if (strlen($payload) > 262144) {
    $respond(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$signature = (string) ($_SERVER['HTTP_BLADECP_SIGNATURE'] ?? '');
$webhookId = (string) ($_SERVER['HTTP_X_WEBHOOK_ID'] ?? '');

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $webhookId)) {
    $respond(400, ['ok' => false, 'error' => 'invalid_webhook_id']);
}

if (!WebhookReceiver::verifySignature($payload, $signature, $secret)) {
    $respond(401, ['ok' => false, 'error' => 'invalid_signature']);
}

try {
    $candidate = class_exists(\WHMCS\Database\Capsule::class)
        && \WHMCS\Database\Capsule::table('tblservers')
            ->where('id', $serverId)
            ->where('type', 'bladecp')
            ->where('disabled', 0)
            ->exists();
} catch (\Throwable) {
    $candidate = false;
}
if (!$candidate) {
    $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
}

$parsedEvent = WebhookReceiver::parseEventResult($payload);
$event = $parsedEvent['event'];
if ($event === null) {
    $respond(400, ['ok' => false, 'error' => $parsedEvent['error']]);
}
if (($event['accountId'] ?? null) !== null
    && !hash_equals(WebhookConfig::getEndpointId($serverId), (string) ($event['endpointUuid'] ?? ''))) {
    $respond(400, ['ok' => false, 'error' => 'invalid_vibe_endpoint']);
}

$claim = WebhookEventStore::claim(
    $serverId,
    $webhookId,
    (string) $event['event'],
    (string) $event['resourceId'],
    WebhookConfig::getEndpointId($serverId),
    $event['resourceVersion']
);
if ($claim['state'] === 'processed') {
    $respond(200, ['ok' => true, 'duplicate' => true]);
}
if ($claim['state'] === 'ignored') {
    $respond(202, ['ok' => true, 'ignored' => true]);
}
if ($claim['state'] !== 'claimed') {
    $respond(503, ['ok' => false, 'error' => 'receiver_busy']);
}

// Evento sem mapeamento acionável (ex.: provision.created, status
// desconhecido) → 202 (recebido, ignorado de propósito; não é erro).
if (!is_string($event['whmcsStatus']) || $event['whmcsStatus'] === '') {
    if (!WebhookEventStore::complete($claim, 'ignored')) {
        $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
    }
    $respond(202, ['ok' => true, 'ignored' => true, 'event' => $event['event']]);
}

$result = WebhookReceiver::applyToService($event, $serverId);
if (function_exists('logModuleCall')) {
    // Só type/uuid/resultado — nunca o corpo cru nem o segredo.
    logModuleCall(
        'bladecp',
        'WebhookReceiver',
        [
            'event' => $event['event'],
            'resourceId' => $event['resourceId'],
            'resourceKind' => $event['accountId'] !== null ? 'vibe_account' : 'provision',
        ],
        ['ok' => !empty($result['ok']), 'reason' => (string) ($result['reason'] ?? '')]
    );
}
$ok = !empty($result['ok']);
if ($ok) {
    if (!WebhookEventStore::complete($claim, 'processed')) {
        $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
    }
    $respond(200, [
        'ok' => true,
        'event' => $event['event'],
    ]);
}

$reason = (string) ($result['reason'] ?? '');
if ($reason === 'not-actionable') {
    if (!WebhookEventStore::complete($claim, 'ignored')) {
        $respond(503, ['ok' => false, 'error' => 'receiver_unavailable']);
    }
    $respond(202, [
        'ok' => true,
        'ignored' => true,
        'event' => $event['event'],
    ]);
}
WebhookEventStore::fail($claim, $reason === '' ? 'apply_failed' : $reason);
$respond(503, [
    'ok' => false,
    'event' => $event['event'],
    'error' => 'receiver_unavailable',
]);
