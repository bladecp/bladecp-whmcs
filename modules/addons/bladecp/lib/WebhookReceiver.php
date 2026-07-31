<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Ingestão de eventos BladeCP→WHMCS.
 *
 * Hoje o módulo é PUSH-ONLY (WHMCS → BladeCP): quando a infra é
 * suspensa/destruída fora do WHMCS, o serviço fica "Active" eternamente.
 * Este receiver fecha a perna inversa: a BladeCP emite
 * `provision.created | provision.suspended | provision.unsuspended |
 * provision.terminated | provision.status.changed` e nós refletimos no
 * status do serviço WHMCS.
 *
 * O endpoint HTTP vive em `modules/addons/bladecp/webhook.php` (arquivo
 * batido direto, fora do roteamento do WHMCS). Esta classe é a LÓGICA:
 *  - `verifySignature()` / `parseSignatureHeader()` / `mapEventToStatus()`
 *    / `parseEvent()` são PURAS (sem rede/sem DB) → cobertas pelo
 *  - `markEvent()` deduplica por `X-Webhook-Id` (DB quando há Capsule;
 *    in-memory por processo no fallback).
 *  - `applyToService()` reflete o status DIRETO em `tblhosting`
 *    (Capsule) — NUNCA via `localAPI('ModuleSuspend'...)`, senão o
 *    evento que VEIO da BladeCP dispararia uma chamada DE VOLTA pra
 *    BladeCP (loop). Fail-soft: sem Capsule/serviço → no-op.
 *
 * Contrato de assinatura (Stripe-style; NÃO é `X-BladeCP-Signature:
 * sha256=`): header `BladeCP-Signature: t=<unix>,v1=<hex>` onde
 * `v1 = HMAC_SHA256(secret, "<t>.<rawBody>")`. Verificação:
 * recomputa o HMAC, compara em tempo constante (`hash_equals`) e checa
 * a janela anti-replay no `t` (±5 min). O segredo vem do
 * `WebhookConfig` (option do addon → constante → env) — ver `webhook.php`.
 */
final class WebhookReceiver
{
    /** Janela anti-replay padrão (segundos) para o timestamp `t`. */
    public const SIGNATURE_TOLERANCE = 300;


    /** Evento direto → status de serviço WHMCS (domainstatus). */
    public const STATUS_MAP = [
        'provision.suspended' => 'Suspended',
        'provision.unsuspended' => 'Active',
        'provision.terminated' => 'Terminated',
        'provision.deleted' => 'Terminated',
        'provision.active' => 'Active',
        'provision.activated' => 'Active',
    ];

    /** Status de provisão (em `status.changed`) → status WHMCS. */
    public const PROVISION_STATUS_MAP = [
        'pending' => 'Pending',
        'pending_manual' => 'Pending',
        'provisioning' => 'Pending',
        'active' => 'Active',
        'running' => 'Active',
        'provisioned' => 'Active',
        'suspended' => 'Suspended',
        'past_due' => 'Suspended',
        'terminated' => 'Terminated',
        'deleted' => 'Terminated',
        'cancelled' => 'Cancelled',
        'canceled' => 'Cancelled',
    ];

    /**
     * Mapeia (evento [, status de provisão]) → status WHMCS, ou `null`
     * quando o evento não é acionável. `status.changed` consulta o
     * status carregado; os eventos diretos usam o mapa fixo.
     */
    public static function mapEventToStatus(
        string $event,
        string $provisionStatus = ''
    ): ?string {
        $event = strtolower(trim($event));
        if (
            $event === 'status.changed'
            || $event === 'provision.status.changed'
            || $event === 'provision.status_changed'
        ) {
            $s = strtolower(trim($provisionStatus));
            return self::PROVISION_STATUS_MAP[$s] ?? null;
        }
        return self::STATUS_MAP[$event] ?? null;
    }

    /**
     * Pura: payload (string JSON ou array) → evento normalizado, ou
     * `null` se faltar `event`/`provisionUuid`. Tolera o envelope real
     * `{id,type,created,data}` e nomes camelCase/snake_case (o tipo
     * chega em `type`; o uuid em `data.provisionUuid`).
     *
     * @return array{event:string,eventUuid:string,provisionUuid:string,status:string,resourceVersion:?string,whmcsStatus:?string}|null
     */
    public static function parseEvent(mixed $json): ?array
    {
        $result = self::parseEventResult($json);
        return $result['event'];
    }

    /**
     * Validação tipada do envelope. Erros deste estágio são permanentes: o
     * evento ainda não entrou no ledger e nenhuma mutação foi realizada.
     *
     * @return array{event:array{event:string,eventUuid:string,provisionUuid:string,status:string,resourceVersion:?string,whmcsStatus:?string}|null,error:string}
     */
    public static function parseEventResult(mixed $json): array
    {
        if (is_string($json)) {
            $json = json_decode($json, true);
        }
        if (!is_array($json)) {
            return ['event' => null, 'error' => 'invalid_payload'];
        }
        $data = $json['data'] ?? null;
        $inner = (is_array($data) && !array_is_list($data)) ? $data : $json;

        $event = trim((string) (
            $json['type']
            ?? $json['event']
            ?? $inner['type']
            ?? $inner['event']
            ?? ''
        ));
        $eventUuid = trim((string) ($json['id'] ?? ''));
        $uuid = trim((string) (
            $inner['provisionUuid']
            ?? $inner['provision_uuid']
            ?? $inner['uuid']
            ?? $json['provisionUuid']
            ?? $json['provision_uuid']
            ?? ''
        ));
        $status = trim((string) (
            $inner['status']
            ?? $inner['provisionStatus']
            ?? $inner['provision_status']
            ?? ''
        ));
        $resourceVersion = $inner['resourceVersion']
            ?? $inner['resource_version']
            ?? null;
        if ($event === '') {
            return ['event' => null, 'error' => 'invalid_payload'];
        }
        if (!self::isCanonicalUuid($eventUuid)) {
            return ['event' => null, 'error' => 'invalid_event_id'];
        }
        if (!self::isCanonicalUuid($uuid)) {
            return ['event' => null, 'error' => 'invalid_provision_uuid'];
        }
        $version = self::resourceVersion($resourceVersion);
        if ($resourceVersion !== null && $version === null) {
            return ['event' => null, 'error' => 'invalid_resource_version'];
        }
        return [
            'event' => [
                'event' => $event,
                'eventUuid' => $eventUuid,
                'provisionUuid' => $uuid,
                'status' => $status,
                'resourceVersion' => $version,
                'whmcsStatus' => self::mapEventToStatus($event, $status),
            ],
            'error' => '',
        ];
    }

    private static function isCanonicalUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        ) === 1;
    }

    private static function resourceVersion(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return $value;
        }
        return null;
    }

    /**
     * Pura: faz o parse do header `BladeCP-Signature: t=<unix>,v1=<hex>`.
     * Tolera ordem dos pares e espaços. Retorna `['t'=>int,'v1'=>string]`
     * ou `null` quando faltar `t`/`v1` ou `t` não for inteiro.
     *
     * @return array{t:int,v1:string}|null
     */
    public static function parseSignatureHeader(string $header): ?array
    {
        $t = null;
        $v1 = null;
        foreach (explode(',', trim($header)) as $part) {
            $part = trim($part);
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            $k = trim(substr($part, 0, $eq));
            $v = trim(substr($part, $eq + 1));
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $v1 = $v;
            }
        }
        if ($t === null || $v1 === null || $v1 === '' || !ctype_digit($t)) {
            return null;
        }
        return ['t' => (int) $t, 'v1' => $v1];
    }

    /**
     * Pura: confere a assinatura Stripe-style do corpo cru.
     *  - parseia `t`/`v1` do header `BladeCP-Signature`;
     *  - recomputa `HMAC_SHA256(secret, "<t>.<rawBody>")`;
     *  - compara em tempo constante (`hash_equals`);
     *  - anti-replay: rejeita se `|now - t|` exceder `$tolerance`.
     * Segredo/header vazios → false. `$now` injetável p/ teste.
     */
    public static function verifySignature(
        string $payload,
        string $signatureHeader,
        string $secret,
        ?int $now = null,
        int $tolerance = self::SIGNATURE_TOLERANCE
    ): bool {
        if ($secret === '' || trim($signatureHeader) === '') {
            return false;
        }
        $parsed = self::parseSignatureHeader($signatureHeader);
        if ($parsed === null) {
            return false;
        }
        $now ??= time();
        if ($tolerance > 0 && abs($now - $parsed['t']) > $tolerance) {
            return false; // fora da janela → replay/clock skew
        }
        $expected = hash_hmac(
            'sha256',
            $parsed['t'] . '.' . $payload,
            $secret
        );
        return hash_equals($expected, $parsed['v1']);
    }

    /**
     * Idempotência: registra o `X-Webhook-Id` e diz se é a PRIMEIRA vez
     * que o vemos. `true` = novo (processa); `false` = duplicado (replay
     * → pular). Sem id → `true` (não dá pra deduplicar; processa).
     *
     * DB-backed quando há Capsule (persiste entre requests = pega os
     * novas tentativas de entrega); fallback em memória por processo
     * (testes / fora do WHMCS). Fail-soft: erro de DB → processa (a
     * escrita de status é idempotente, então reprocessar é inócuo).
     */
    public static function markEvent(string $webhookId, int $serverId = 0): bool
    {
        $id = trim($webhookId);
        if ($id === '') {
            return true;
        }
        $claim = WebhookEventStore::claim(max(0, $serverId), $id, 'legacy', '');
        return $claim['state'] === 'claimed';
    }

    /**
     * Reflete o status do evento no serviço WHMCS correspondente
     * (DIRETO em `tblhosting`, sem re-chamar o módulo). Resolve o
     * serviço pelo custom field "BladeCP Provision UUID". Fail-soft:
     * sem Capsule / sem serviço / status não-acionável → no-op.
     *
     * @param array{provisionUuid?:string,whmcsStatus?:?string} $event
     * @return array{ok:bool,reason?:string,serviceId?:int,status?:string}
     */
    public static function applyToService(array $event, int $serverId = 0): array
    {
        $uuid = trim((string) ($event['provisionUuid'] ?? ''));
        $status = $event['whmcsStatus'] ?? null;
        if ($uuid === '') {
            return ['ok' => false, 'reason' => 'no-uuid'];
        }
        if (!is_string($status) || $status === '') {
            return ['ok' => false, 'reason' => 'not-actionable'];
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'reason' => 'no-db'];
        }
        try {
            // Serviço cujo custom field "BladeCP Provision UUID" == uuid.
            $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues as v')
                ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                ->join('tblhosting as h', 'h.id', '=', 'v.relid')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('f.fieldname', 'BladeCP Provision UUID')
                ->where('f.type', 'product')
                ->where('v.value', $uuid)
                ->where('p.servertype', 'bladecp')
                ->select(['v.relid', 'h.server'])
                ->first();
            $serviceId = $row !== null ? (int) ($row->relid ?? 0) : 0;
            if ($serviceId <= 0) {
                return ['ok' => false, 'reason' => 'service-not-found'];
            }
            if ($serverId > 0 && (int) ($row->server ?? 0) !== $serverId) {
                return ['ok' => false, 'reason' => 'service-not-found'];
            }
            \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->update(['domainstatus' => $status]);
            return [
                'ok' => true,
                'serviceId' => $serviceId,
                'status' => $status,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'storage-error'];
        }
    }
}
