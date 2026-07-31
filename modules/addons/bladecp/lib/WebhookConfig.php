<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Registro do endpoint de webhook e guarda do segredo de assinatura.
 *
 * O parceiro registra o receiver com a SUA API key (o `Client` já
 * resolve a credencial do Server record):
 *
 *   POST /api/v1/customer/webhook-endpoints
 *     { url, events } → { data: { uuid, ..., secret: "whsec_..." } }
 *
 * O `secret` volta UMA vez da API. Guardamos o valor criptografado numa option do
 * addon em `tbladdonmodules` (module=bladecp), de onde o `webhook.php`
 * o lê via `getSecret()`, sem devolvê-lo à interface. O `uuid` do endpoint
 * é guardado p/ a rotação:
 *
 *   POST /api/v1/customer/webhook-endpoints/:uuid/rotate-secret
 *
 * `getSecret()` tem cadeia de fallback: option do addon → constante
 * `BLADECP_WEBHOOK_SECRET` (configuration.php) → env. Assim o admin
 * pode "Conectar webhooks" para fazer a configuração automática. Constante
 * e variável de ambiente permanecem como fontes operacionais externas.
 *
 * O `Client::url()` prefixa `/customer/`, então os paths passados aqui
 * são relativos a esse escopo (`/webhook-endpoints`). O `Client::post()`
 * já desembrulha `{data:...}` → a resposta chega como `{id,...,secret}`;
 * mesmo assim `extractSecret`/`extractId` toleram o envelope `{data:{}}`
 * (defesa em profundidade). Tudo fail-soft; o segredo NUNCA é logado.
 */
final class WebhookConfig
{
    public const MODULE = 'bladecp';
    public const SETTING_SECRET = 'webhook_secret';
    public const SETTING_ENDPOINT_ID = 'webhook_endpoint_id';
    public const SETTING_RECONNECT_REQUIRED = 'webhook_reconnect_required';

    /** Eventos que o receptor reflete no WHMCS. */
    public const EVENTS = [
        'provision.created',
        'provision.suspended',
        'provision.unsuspended',
        'provision.terminated',
        'provision.status.changed',
    ];

    // ── leitura usada pelo webhook.php ──

    /**
     * Segredo de assinatura: option criptografada do addon → constante →
     * env. `''` quando nenhum dos três existe. Plaintext legado no banco
     * nunca é ativado como segredo.
     */
    public static function getSecret(?int $serverId = null): string
    {
        $serverId = self::resolveServerId($serverId);
        if ($serverId <= 0) {
            return '';
        }
        if (self::requiresReconnect($serverId)) {
            return '';
        }
        $stored = self::readSetting(self::serverSetting(
            self::SETTING_SECRET,
            $serverId
        ));
        if ($stored !== '') {
            $dec = self::decrypt($stored);
            if ($dec !== '') {
                return $dec;
            }
        }
        if (!self::legacyCompatible($serverId)) {
            return '';
        }
        $legacy = self::readSetting(self::SETTING_SECRET);
        if ($legacy !== '') {
            $dec = self::decrypt($legacy);
            if ($dec !== '') {
                return $dec;
            }
        }
        if (defined('BLADECP_WEBHOOK_SECRET')) {
            $c = trim((string) constant('BLADECP_WEBHOOK_SECRET'));
            if ($c !== '') {
                return $c;
            }
        }
        return trim((string) (getenv('BLADECP_WEBHOOK_SECRET') ?: ''));
    }

    /** Há um segredo configurado (sem revelar o valor)? */
    public static function hasSecret(?int $serverId = null): bool
    {
        return self::getSecret($serverId) !== '';
    }

    /** Origem do segredo p/ a UI: 'stored' | 'constant' | 'env' | 'none'. */
    public static function secretSource(?int $serverId = null): string
    {
        $serverId = self::resolveServerId($serverId);
        if ($serverId <= 0) {
            return 'none';
        }
        if (self::requiresReconnect($serverId)) {
            return 'reconnect';
        }
        $scoped = self::readSetting(self::serverSetting(
            self::SETTING_SECRET,
            $serverId
        ));
        if ($scoped !== '' && self::decrypt($scoped) !== '') {
            return 'stored';
        }
        if (!self::legacyCompatible($serverId)) {
            return 'none';
        }
        $legacy = self::readSetting(self::SETTING_SECRET);
        if ($legacy !== '' && self::decrypt($legacy) !== '') {
            return 'stored';
        }
        if (
            defined('BLADECP_WEBHOOK_SECRET')
            && trim((string) constant('BLADECP_WEBHOOK_SECRET')) !== ''
        ) {
            return 'constant';
        }
        if (trim((string) (getenv('BLADECP_WEBHOOK_SECRET') ?: '')) !== '') {
            return 'env';
        }
        return self::hasLegacyPlaintextSecret($serverId) ? 'legacy' : 'none';
    }

    /** Detecta configuração antiga insegura sem ativar ou revelar seu valor. */
    public static function hasLegacyPlaintextSecret(?int $serverId = null): bool
    {
        $serverId = self::resolveServerId($serverId);
        if ($serverId <= 0) {
            return false;
        }
        $scoped = self::readSetting(self::serverSetting(
            self::SETTING_SECRET,
            $serverId
        ));
        if (self::isLegacyPlaintext($scoped)) {
            return true;
        }
        return self::legacyCompatible($serverId)
            && self::isLegacyPlaintext(self::readSetting(self::SETTING_SECRET));
    }

    public static function getEndpointId(?int $serverId = null): string
    {
        $serverId = self::resolveServerId($serverId);
        if ($serverId <= 0) {
            return '';
        }
        if (self::requiresReconnect($serverId)) {
            return '';
        }
        $endpoint = self::readSetting(self::serverSetting(
            self::SETTING_ENDPOINT_ID,
            $serverId
        ));
        if ($endpoint !== '') {
            return $endpoint;
        }
        return self::legacyCompatible($serverId)
            ? self::readSetting(self::SETTING_ENDPOINT_ID)
            : '';
    }

    /** Indica que uma operação remota incerta exige nova conexão explícita. */
    public static function requiresReconnect(?int $serverId = null): bool
    {
        $serverId = self::resolveServerId($serverId);
        return $serverId > 0 && self::readSetting(self::serverSetting(
            self::SETTING_RECONNECT_REQUIRED,
            $serverId
        )) === '1';
    }

    /** Servidor administrativo inequívoco, ou zero quando não configurado. */
    public static function managementServerId(): int
    {
        if (!class_exists(AdminServerContext::class)) {
            return 0;
        }
        try {
            $context = AdminServerContext::resolve();
            return ($context['state'] ?? '') === 'configured'
                ? max(0, (int) ($context['serverId'] ?? 0))
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    // ── URL pública do receiver ──

    /** URL pública do `webhook.php` derivada do System URL do WHMCS. */
    public static function publicUrl(): string
    {
        $base = self::systemUrl();
        if ($base === '') {
            return '';
        }
        return rtrim($base, '/')
            . '/modules/addons/bladecp/webhook.php';
    }

    /**
     * Classifica a URL do receiver sem tentar acessá-la. A classificação é
     * deliberadamente separada do registro: um WHMCS local é um ambiente
     * válido para desenvolvimento, mas não pode receber chamadas da
     * plataforma sem uma URL HTTPS pública.
     *
     * @return array{state:string,environment:string,detail:string,action:string}
     */
    public static function classifyPublicUrl(string $url = ''): array
    {
        $url = trim($url !== '' ? $url : self::publicUrl());
        if ($url === '') {
            return [
                'state' => 'blocked',
                'environment' => 'unknown',
                'detail' => 'O System URL do WHMCS não está configurado.',
                'action' => 'Configure o System URL ou informe a URL pública do receiver.',
            ];
        }
        if (strlen($url) > 2048 || preg_match('/\s/', $url) === 1) {
            return self::blockedUrl('A URL do receiver não é válida.');
        }
        $parts = @parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? self::normalizeHost((string) ($parts['host'] ?? '')) : '';
        if (
            !is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || $scheme === ''
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            return self::blockedUrl('A URL do receiver não é uma URL HTTP válida.');
        }
        if (self::isLocalHost($host)) {
            return [
                'state' => 'local',
                'environment' => 'local',
                'detail' => 'Este é um ambiente local. A plataforma não consegue chamar este endereço diretamente.',
                'action' => 'Para testar webhooks localmente, use um túnel HTTPS público. Em produção, use uma URL HTTPS pública.',
            ];
        }
        if ($scheme !== 'https') {
            return self::blockedUrl(
                'A URL pública do receiver não usa HTTPS.',
                'Publique o WHMCS com HTTPS e use essa URL antes de conectar os webhooks.'
            );
        }
        if (!self::isReceiverPath((string) ($parts['path'] ?? ''))) {
            return self::blockedUrl('A URL não aponta para o receiver do módulo.');
        }
        $hostStatus = self::publicHostStatus($host);
        if ($hostStatus === 'local') {
            return [
                'state' => 'local',
                'environment' => 'local',
                'detail' => 'O receiver resolve para uma rede local ou reservada.',
                'action' => 'Para testar webhooks localmente, use um túnel HTTPS público. Em produção, use uma URL HTTPS pública.',
            ];
        }
        if ($hostStatus !== 'public') {
            return self::blockedUrl('Não foi possível validar o host do receiver como público e seguro.');
        }
        return [
            'state' => 'ok',
            'environment' => 'public',
            'detail' => 'A URL parece pública e usa HTTPS.',
            'action' => 'Confirme que o caminho pode receber chamadas externas.',
        ];
    }

    private static function systemUrl(): string
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return '';
        }
        try {
            foreach (['SystemURL', 'Domain'] as $key) {
                $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                    ->where('setting', $key)
                    ->first();
                $val = $row !== null ? trim((string) ($row->value ?? '')) : '';
                if ($val !== '') {
                    return $val;
                }
            }
        } catch (\Throwable) {
            // fail-soft
        }
        return '';
    }

    // ── registro + rotação (API) ──

    /**
     * Registra o endpoint na BladeCP e guarda segredo + id sem devolver o
     * valor sensível ao caller. URL vazia deriva do System URL.
     *
     * @return array{ok:bool,error:string}
     */
    public static function connect(string $url = ''): array
    {
        $serverId = self::managementServerId();
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'error' => 'Selecione uma conta BladeCP válida para as operações administrativas.',
            ];
        }
        $url = trim($url);
        if ($url === '') {
            $url = self::publicUrl();
        }
        $classification = self::classifyPublicUrl($url);
        if ($classification['state'] !== 'ok') {
            return [
                'ok' => false,
                'error' => 'A URL do receiver precisa ser HTTPS pública, segura e acessível externamente.',
            ];
        }
        $url = self::urlForServer($url, $serverId);
        if ($url === '') {
            return [
                'ok' => false,
                'error' => 'Não foi possível preparar a URL segura do receiver.',
            ];
        }
        if (!self::canEncrypt()) {
            return [
                'ok' => false,
                'error' => 'A criptografia do WHMCS não está disponível. Nenhuma configuração foi alterada.',
            ];
        }
        if (!self::canPersistSettings($serverId)) {
            return [
                'ok' => false,
                'error' => 'O WHMCS não conseguiu validar a persistência segura. Nenhuma configuração foi alterada.',
            ];
        }
        $client = self::client($serverId);
        if ($client === null) {
            return [
                'ok' => false,
                'error' => 'Servidor BladeCP não configurado. Conecte sua '
                    . 'API key primeiro (aba Configuração rápida).',
            ];
        }
        try {
            $resp = $client->post('/webhook-endpoints', [
                'url' => $url,
                'events' => self::EVENTS,
            ]);
        } catch (\Throwable) {
            return [
                'ok' => false,
                'error' => 'Não foi possível registrar o endpoint agora. Tente novamente mais tarde.',
            ];
        }
        $uuid = self::extractEndpointUuid($resp);
        $secret = self::extractSecret($resp);
        if ($secret === '' || $uuid === '') {
            if ($uuid !== '') {
                self::deleteRemoteEndpoint($client, $uuid);
            }
            return [
                'ok' => false,
                'error' => 'A resposta do endpoint não pôde ser validada. A configuração anterior foi preservada.',
            ];
        }
        $encryptedSecret = self::encrypt($secret);
        if (
            $encryptedSecret === ''
            || !self::writeSettingsAtomically([
                self::serverSetting(self::SETTING_SECRET, $serverId) => $encryptedSecret,
                self::serverSetting(self::SETTING_ENDPOINT_ID, $serverId) => $uuid,
                self::serverSetting(self::SETTING_RECONNECT_REQUIRED, $serverId) => '0',
            ])
        ) {
            $compensated = self::deleteRemoteEndpoint($client, $uuid);
            return [
                'ok' => false,
                'error' => $compensated
                    ? 'A configuração segura não pôde ser salva. O registro remoto foi desfeito e a configuração anterior foi preservada.'
                    : 'A configuração segura não pôde ser salva. A configuração anterior foi preservada; revise os endpoints remotos antes de tentar novamente.',
            ];
        }
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Rotaciona o segredo do endpoint registrado e guarda o novo.
     *
     * @return array{ok:bool,error:string}
     */
    public static function rotate(): array
    {
        $serverId = self::managementServerId();
        if ($serverId <= 0) {
            return [
                'ok' => false,
                'error' => 'Selecione uma conta BladeCP válida para as operações administrativas.',
            ];
        }
        $id = self::getEndpointId($serverId);
        if ($id === '') {
            return [
                'ok' => false,
                'error' => 'Nenhum endpoint registrado para rotacionar. '
                    . 'Use "Conectar webhooks" primeiro.',
            ];
        }
        $client = self::client($serverId);
        if ($client === null) {
            return [
                'ok' => false,
                'error' => 'Servidor BladeCP não configurado.',
            ];
        }
        if (!self::canEncrypt()) {
            return [
                'ok' => false,
                'error' => 'A criptografia do WHMCS não está disponível. A configuração atual foi preservada.',
            ];
        }
        if (!self::canPersistSettings($serverId)) {
            return [
                'ok' => false,
                'error' => 'O WHMCS não conseguiu validar a persistência segura. A configuração atual foi preservada.',
            ];
        }
        try {
            $resp = $client->post(
                '/webhook-endpoints/' . rawurlencode($id) . '/rotate-secret',
                []
            );
        } catch (\Throwable) {
            if (self::recoverRotation($client, $id, $serverId)) {
                return ['ok' => true, 'error' => ''];
            }
            $deactivated = self::deleteRemoteEndpoint($client, $id);
            self::markReconnectRequired($serverId);
            return [
                'ok' => false,
                'error' => $deactivated
                    ? 'A rotação não pôde ser confirmada. O endpoint foi desativado; reconecte os webhooks.'
                    : 'A rotação não pôde ser confirmada. Reconecte os webhooks antes de receber novos eventos.',
            ];
        }
        $secret = self::extractSecret($resp);
        $encryptedSecret = self::encrypt($secret);
        if (
            $encryptedSecret === ''
            || !self::writeSettingsAtomically([
                self::serverSetting(self::SETTING_SECRET, $serverId) => $encryptedSecret,
                self::serverSetting(self::SETTING_RECONNECT_REQUIRED, $serverId) => '0',
            ])
        ) {
            if (self::recoverRotation($client, $id, $serverId)) {
                return ['ok' => true, 'error' => ''];
            }
            $deactivated = self::deleteRemoteEndpoint($client, $id);
            self::markReconnectRequired($serverId);
            return [
                'ok' => false,
                'error' => $deactivated
                    ? 'A rotação não pôde ser concluída com segurança. O endpoint foi desativado; reconecte os webhooks.'
                    : 'A rotação não pôde ser concluída com segurança. Reconecte os webhooks antes de receber novos eventos.',
            ];
        }
        return ['ok' => true, 'error' => ''];
    }

    // ── parsing puro (testável) ──

    /** Extrai o `secret` da resposta (raiz ou `data`). Puro. */
    public static function extractSecret(mixed $resp): string
    {
        return self::pluckString($resp, 'secret');
    }

    /** Extrai o `uuid` canônico da resposta (raiz ou `data`). Puro. */
    public static function extractEndpointUuid(mixed $resp): string
    {
        return self::pluckString($resp, 'uuid');
    }

    /**
     * Alias retrocompatível para consumidores/testes antigos. A API atual
     * identifica endpoints por UUID; `id` numérico só é aceito como fallback
     * durante rollout coordenado.
     */
    public static function extractId(mixed $resp): string
    {
        return self::extractEndpointUuid($resp) ?: self::pluckString($resp, 'id');
    }

    /** Pega uma chave string/int da raiz ou de `data`. Puro. */
    private static function pluckString(mixed $resp, string $key): string
    {
        if (!is_array($resp)) {
            return '';
        }
        foreach ([$resp, $resp['data'] ?? null] as $src) {
            if (!is_array($src) || !array_key_exists($key, $src)) {
                continue;
            }
            $v = $src[$key];
            if (is_string($v) || is_int($v)) {
                $s = trim((string) $v);
                if ($s !== '') {
                    return $s;
                }
            }
        }
        return '';
    }

    // ── persistência (tbladdonmodules) ──

    public static function setSecret(string $secret, ?int $serverId = null): bool
    {
        $serverId = self::resolveServerId($serverId);
        if ($serverId <= 0) {
            return false;
        }
        $encrypted = self::encrypt(trim($secret));
        return $encrypted !== '' && self::writeSettingsAtomically([
            self::serverSetting(self::SETTING_SECRET, $serverId) => $encrypted,
        ]);
    }

    private static function readSetting(string $key): string
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return '';
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->where('setting', $key)
                ->first();
            return $row !== null ? trim((string) ($row->value ?? '')) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function writeSetting(string $key, string $value): bool
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->where('setting', $key)
                ->exists();
            if ($exists) {
                \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->where('module', self::MODULE)
                    ->where('setting', $key)
                    ->update(['value' => $value]);
            } else {
                \WHMCS\Database\Capsule::table('tbladdonmodules')->insert([
                    'module' => self::MODULE,
                    'setting' => $key,
                    'value' => $value,
                ]);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Persiste um conjunto de configurações como uma única alteração. Isso
     * evita trocar o segredo sem conseguir guardar o endpoint correspondente.
     * Uma exceção faz rollback e preserva a configuração anterior.
     *
     * @param array<string,string> $settings
     */
    private static function writeSettingsAtomically(array $settings): bool
    {
        if ($settings === [] || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            \WHMCS\Database\Capsule::connection()->transaction(
                static function () use ($settings): void {
                    foreach ($settings as $key => $value) {
                        $query = \WHMCS\Database\Capsule::table('tbladdonmodules')
                            ->where('module', self::MODULE)
                            ->where('setting', $key);
                        if ($query->exists()) {
                            $query->update(['value' => $value]);
                            continue;
                        }
                        \WHMCS\Database\Capsule::table('tbladdonmodules')->insert([
                            'module' => self::MODULE,
                            'setting' => $key,
                            'value' => $value,
                        ]);
                    }
                }
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Confirma escrita, atualização e remoção antes de qualquer mutação
     * remota irreversível. A transação termina com o mesmo estado inicial.
     */
    private static function canPersistSettings(int $serverId): bool
    {
        if ($serverId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        $key = 'webhook_persistence_probe_server_' . $serverId;
        try {
            \WHMCS\Database\Capsule::connection()->transaction(
                static function () use ($key): void {
                    $query = \WHMCS\Database\Capsule::table('tbladdonmodules')
                        ->where('module', self::MODULE)
                        ->where('setting', $key);
                    $row = $query->first();
                    $existed = $row !== null;
                    $previous = $existed
                        ? (string) ($row->value ?? '')
                        : '';
                    if ($existed) {
                        $query->update(['value' => 'probe']);
                        $query->update(['value' => $previous]);
                        return;
                    }
                    \WHMCS\Database\Capsule::table('tbladdonmodules')->insert([
                        'module' => self::MODULE,
                        'setting' => $key,
                        'value' => 'probe',
                    ]);
                    \WHMCS\Database\Capsule::table('tbladdonmodules')
                        ->where('module', self::MODULE)
                        ->where('setting', $key)
                        ->delete();
                }
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function deleteRemoteEndpoint(
        \WHMCS\Module\Server\Bladecp\Api\Client $client,
        string $endpointId
    ): bool {
        $endpointId = trim($endpointId);
        if ($endpointId === '') {
            return false;
        }
        try {
            $client->delete(
                '/webhook-endpoints/' . rawurlencode($endpointId)
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Uma rotação remota já invalida o segredo anterior. Se a primeira
     * persistência falhar apesar do preflight, uma segunda rotação limitada
     * recupera um segredo novo e o persiste sem jamais devolvê-lo ao caller.
     */
    private static function recoverRotation(
        \WHMCS\Module\Server\Bladecp\Api\Client $client,
        string $endpointId,
        int $serverId
    ): bool {
        try {
            $response = $client->post(
                '/webhook-endpoints/' . rawurlencode($endpointId)
                    . '/rotate-secret',
                []
            );
            $secret = self::extractSecret($response);
            $encrypted = self::encrypt($secret);
            return $encrypted !== '' && self::writeSettingsAtomically([
                self::serverSetting(self::SETTING_SECRET, $serverId)
                    => $encrypted,
                self::serverSetting(
                    self::SETTING_RECONNECT_REQUIRED,
                    $serverId
                ) => '0',
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function markReconnectRequired(int $serverId): void
    {
        self::writeSettingsAtomically([
            self::serverSetting(
                self::SETTING_RECONNECT_REQUIRED,
                $serverId
            ) => '1',
        ]);
    }

    // ── cripto (falha fechada: jamais grava segredo em texto simples) ──

    private static function encrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (function_exists('localAPI')) {
            try {
                $r = localAPI('EncryptPassword', ['password2' => $value]);
                $enc = trim((string) ($r['password'] ?? ''));
                if (
                    ($r['result'] ?? '') !== 'success'
                    || $enc === ''
                    || hash_equals($value, $enc)
                ) {
                    return '';
                }

                return $enc;
            } catch (\Throwable) {
                // Falha fechada: não persiste o valor original.
            }
        }
        return '';
    }

    private static function canEncrypt(): bool
    {
        try {
            return self::encrypt(bin2hex(random_bytes(16))) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function decrypt(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (self::isLegacyPlaintext($raw)) {
            return '';
        }
        if (function_exists('localAPI')) {
            try {
                $r = localAPI('DecryptPassword', ['password2' => $raw]);
                $dec = trim((string) ($r['password'] ?? ''));
                if ($dec !== '') {
                    return $dec;
                }
            } catch (\Throwable) {
                // Não usa o valor cifrado como se fosse segredo.
            }
        }
        return '';
    }

    private static function isLegacyPlaintext(string $raw): bool
    {
        return str_starts_with(trim($raw), 'whsec_');
    }

    private static function client(int $serverId): ?\WHMCS\Module\Server\Bladecp\Api\Client
    {
        if ($serverId <= 0 || !class_exists(ServerConfig::class)) {
            return null;
        }
        try {
            return ServerConfig::clientForServerId($serverId);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isLocalHost(string $host): bool
    {
        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
        ) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private static function resolveServerId(?int $serverId): int
    {
        if ($serverId === null) {
            return self::managementServerId();
        }
        if ($serverId <= 0 || !class_exists(AdminServerContext::class)) {
            return 0;
        }
        try {
            return AdminServerContext::candidate($serverId) !== null
                ? $serverId
                : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function serverSetting(string $base, int $serverId): string
    {
        return $base . '_server_' . $serverId;
    }

    /**
     * A configuração global anterior só é inequívoca quando há uma única
     * conta elegível, resolvida como o mesmo contexto administrativo.
     */
    private static function legacyCompatible(int $serverId): bool
    {
        if (
            $serverId <= 0
            || !class_exists(AdminServerContext::class)
            || !class_exists(\WHMCS\Database\Capsule::class)
        ) {
            return false;
        }
        try {
            $context = AdminServerContext::resolve();
            if (
                ($context['state'] ?? '') !== 'configured'
                || (int) ($context['serverId'] ?? 0) !== $serverId
            ) {
                return false;
            }

            // O fallback global nasceu antes do suporte multi-servidor e não
            // carrega ownership. Ele só é seguro quando existe exatamente um
            // Server record BladeCP com credencial, inclusive contando records
            // desativados que ainda representam outra configuração possível.
            $rows = \WHMCS\Database\Capsule::table('tblservers')
                ->where('type', 'bladecp')
                ->get(['id', 'accesshash']);
            $configuredIds = [];
            foreach ($rows as $row) {
                $id = (int) ($row->id ?? 0);
                $accessHash = trim((string) ($row->accesshash ?? ''));
                if ($id > 0 && $accessHash !== '') {
                    $configuredIds[] = $id;
                }
            }
            return count($configuredIds) === 1
                && $configuredIds[0] === $serverId;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Adiciona ou substitui `server_id` com query string estável. */
    private static function urlForServer(string $url, int $serverId): string
    {
        if ($serverId <= 0) {
            return '';
        }
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $query = [];
        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            parse_str((string) $parts['query'], $query);
        }
        $query['server_id'] = (string) $serverId;
        ksort($query, SORT_STRING);

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = self::normalizeHost((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https' || $host === '' || $path === '') {
            return '';
        }
        $authorityHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $encodedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $scheme . '://' . $authorityHost . $port . $path
            . ($encodedQuery !== '' ? '?' . $encodedQuery : '');
    }

    /** @return array{state:string,environment:string,detail:string,action:string} */
    private static function blockedUrl(string $detail, string $action = ''): array
    {
        return [
            'state' => 'blocked',
            'environment' => 'public',
            'detail' => $detail,
            'action' => $action !== ''
                ? $action
                : 'Use uma URL HTTPS pública do receiver, acessível externamente.',
        ];
    }

    private static function normalizeHost(string $host): string
    {
        $host = trim($host);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        return strtolower(rtrim($host, '.'));
    }

    private static function isReceiverPath(string $path): bool
    {
        return rtrim($path, '/') === '/modules/addons/bladecp/webhook.php';
    }

    /** @return 'public'|'local'|'invalid' */
    private static function publicHostStatus(string $host): string
    {
        if ($host === '' || strlen($host) > 253) {
            return 'invalid';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isUnsafeIp($host) ? 'local' : 'public';
        }
        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.home')
            || str_ends_with($host, '.lan')
        ) {
            return 'invalid';
        }
        $ips = self::resolveHost($host);
        if ($ips === []) {
            return 'invalid';
        }
        foreach ($ips as $ip) {
            if (self::isUnsafeIp($ip)) {
                return 'local';
            }
        }
        return 'public';
    }

    /** @return list<string> */
    private static function resolveHost(string $host): array
    {
        $ips = [];
        if (function_exists('dns_get_record')) {
            try {
                $records = @dns_get_record($host, DNS_A | DNS_AAAA);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        foreach (['ip', 'ipv6'] as $key) {
                            $candidate = trim((string) ($record[$key] ?? ''));
                            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                                $ips[] = $candidate;
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                return [];
            }
        }
        if ($ips === [] && function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                foreach ($resolved as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                        $ips[] = $candidate;
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    private static function isUnsafeIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach ([
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10],
                ['127.0.0.0', 8], ['169.254.0.0', 16], ['172.16.0.0', 12],
                ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24],
                ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
                ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ] as [$network, $bits]) {
                if (self::ipInCidr($ip, $network, $bits)) {
                    return true;
                }
            }
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return self::isUnsafeIp(inet_ntop(substr($packed, 12)) ?: '');
        }
        foreach ([['::', 128], ['::1', 128], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8]] as [$network, $bits]) {
            if (self::ipInCidr($ip, $network, $bits)) {
                return true;
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $network, int $bits): bool
    {
        $address = @inet_pton($ip);
        $base = @inet_pton($network);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        $remaining = $bits % 8;
        if ($fullBytes > 0 && substr($address, 0, $fullBytes) !== substr($base, 0, $fullBytes)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($address[$fullBytes]) & $mask) === (ord($base[$fullBytes]) & $mask);
    }
}
