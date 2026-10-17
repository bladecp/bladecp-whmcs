<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Authenticated Customer API boundary for a single WHMCS installation and
 * native client. Browser input never supplies either identifier.
 */
final class FastDeployGateway
{
    private const INSTALLATION_SETTING_PREFIX = 'fast_deploy_installation_server_';
    private const COMMERCIAL_CURSOR_SETTING_PREFIX = 'fast_deploy_commercial_cursor_server_';
    private const COMMERCIAL_WORK_PAGES = 10;
    private const COMMERCIAL_WORK_LIMIT = 100;
    private const BILLING_QUEUE_LIMIT = 50;
    private const REQUEST_UUID = '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di';
    private const OPERATIONS = [
        'upload-url', 'complete-upload', 'confirm-upload', 'discovery-review',
        'approve', 'cancel', 'retry', 'service', 'commercial',
    ];

    private function __construct(
        private readonly int $serverId,
        private readonly int $clientId,
        private readonly string $installationId,
        private readonly Client $client,
        private readonly bool $serverOnly = false
    ) {
    }

    /** Cursor to persist only after the caller has attempted the returned work page. */
    private ?string $pendingCommercialCursor = null;

    /**
     * Linked migrations are visible only to the commercial reconciliation.
     * The client Fast Deploy page keeps showing native requests only.
     */
    private bool $billingLinks = false;

    /** Commercial work limited to linked migrations, when the client area Fast Deploy is off. */
    private bool $linkedOnly = false;

    /** Same authenticated context, also accepting migrations billed through a link to this installation. */
    public function withBillingLinks(): self
    {
        $copy = clone $this;
        $copy->billingLinks = true;
        return $copy;
    }

    /**
     * Internal cron factory. It can inspect only the API's bounded commercial
     * work list and cannot load or mutate a customer's request.
     */
    public static function forServer(int $serverId): ?self
    {
        if ($serverId <= 0 || !ClientExperienceSettings::fastDeployEnabled($serverId)) {
            return null;
        }
        try {
            $client = ServerConfig::clientForServerId($serverId);
            $installationId = self::storedInstallationId($serverId);
            return $client === null || $installationId === null ? null
                : new self($serverId, 0, $installationId, $client, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Internal factory for the billing of cPanel Migrator migrations. It does
     * not depend on the client area Fast Deploy: with that feature off, the
     * commercial work list carries only migrations linked to this
     * installation, never the native requests of the client page.
     */
    public static function forBilling(int $serverId, bool $linkedOnly = false): ?self
    {
        if ($serverId <= 0) {
            return null;
        }
        try {
            $client = ServerConfig::clientForServerId($serverId);
            $installationId = $client === null ? null : self::storedInstallationId($serverId);
            if ($client === null || $installationId === null) {
                return null;
            }
            $gateway = new self($serverId, 0, $installationId, $client, true);
            $gateway->linkedOnly = $linkedOnly || !ClientExperienceSettings::fastDeployEnabled($serverId);
            return $gateway;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function forClient(int $serverId, int $clientId): ?self
    {
        if ($serverId <= 0 || $clientId <= 0) {
            return null;
        }
        try {
            $client = ServerConfig::clientForServerId($serverId);
            $installationId = self::storedInstallationId($serverId);
            if ($client === null || $installationId === null) {
                return null;
            }
            return new self($serverId, $clientId, $installationId, $client);
        } catch (\Throwable) {
            return null;
        }
    }

    public function serverId(): int { return $this->serverId; }
    public function clientId(): int { return $this->clientId; }
    public function installationId(): string { return $this->installationId; }

    /** @return array<string,mixed> */
    public function request(string $method, string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        $pathOnly = (string) strtok($path, '?');
        if (!in_array($method, ['GET', 'POST'], true)
            || preg_match('#^/migrations(?:/[a-f0-9-]{36}(?:/(?:upload-url|complete-upload|confirm-upload|discovery-review|approve|cancel|retry|service|commercial))?)?$#Di', $pathOnly) !== 1) {
            throw new \InvalidArgumentException('Operação Fast Deploy inválida.');
        }
        if ($this->serverOnly) {
            throw new \LogicException('Contexto interno não pode acessar solicitações de cliente.');
        }
        if ($method === 'GET') {
            $separator = str_contains($path, '?') ? '&' : '?';
            return $this->client->get($path . $separator . http_build_query($this->scope()));
        }
        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => self::idempotencyKey($idempotencyKey)];
        return $this->client->post($path, array_merge($payload, $this->scope()), $headers);
    }

    /** @return list<array<string,mixed>> */
    public function list(): array
    {
        if ($this->serverOnly) { return []; }
        $response = $this->request('GET', '/migrations');
        $items = is_array($response['items'] ?? null) ? $response['items'] : $response;
        if (!is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            $accepted = is_array($item) ? $this->accept($item) : null;
            if ($accepted !== null) {
                $result[] = $accepted;
            }
        }
        return $result;
    }

    /** @return array<string,mixed>|null */
    public function detail(string $requestUuid): ?array
    {
        if ($this->serverOnly) { return null; }
        if (!self::requestUuid($requestUuid)) {
            return null;
        }
        $detail = $this->request('GET', '/migrations/' . rawurlencode($requestUuid));
        $accepted = $this->accept($detail);
        return $accepted !== null && ($accepted['requestUuid'] ?? $accepted['request_id'] ?? null) === $requestUuid
            ? $accepted : null;
    }

    /** @return array<string,mixed> */
    public function create(array $payload, string $idempotencyKey): array
    {
        if ($this->serverOnly) { throw new \LogicException('Contexto interno não pode criar solicitações.'); }
        return $this->request('POST', '/migrations', $payload, $idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function action(string $requestUuid, string $operation, array $payload = [], ?string $idempotencyKey = null): array
    {
        if ($this->serverOnly) { throw new \LogicException('Contexto interno não pode alterar solicitações.'); }
        if (!self::requestUuid($requestUuid) || !in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Ação Fast Deploy inválida.');
        }
        $current = $this->detail($requestUuid);
        if ($current === null) {
            throw new \RuntimeException('Solicitação Fast Deploy indisponível.');
        }
        if ($idempotencyKey === null) {
            try {
                $idempotencyKey = 'whmcs-fast-deploy-' . $this->serverId . '-' . $this->clientId
                    . '-' . $requestUuid . '-' . $operation . '-' . substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 24);
            } catch (\Throwable) {
                throw new \InvalidArgumentException('Ação Fast Deploy inválida.');
            }
        }
        return $this->request(
            'POST',
            '/migrations/' . rawurlencode($requestUuid) . '/' . rawurlencode($operation),
            $payload,
            $idempotencyKey
        );
    }

    /** @return array{email:string,name:string}|null */
    public static function owner(int $clientId): ?array
    {
        if ($clientId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $row = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($row === null) {
                return null;
            }
            $email = trim((string) ($row->email ?? ''));
            $name = trim((string) ($row->firstname ?? '') . ' ' . (string) ($row->lastname ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                return null;
            }
            return ['email' => $email, 'name' => $name];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public function commercialWork(): array
    {
        if (!$this->serverOnly) { return []; }
        $this->pendingCommercialCursor = null;
        try {
            $cursor = self::commercialCursor($this->serverId);
            if ($cursor === false) { return []; }
            $result = [];
            for ($page = 0; $page < self::COMMERCIAL_WORK_PAGES; $page++) {
                $query = ['installation_id' => $this->installationId, 'commercial_work' => '1', 'limit' => self::COMMERCIAL_WORK_LIMIT];
                if (is_string($cursor) && $cursor !== '') { $query['after'] = $cursor; }
                $response = $this->client->get('/migrations?' . http_build_query($query));
                $items = is_array($response['items'] ?? null) ? $response['items'] : $response;
                if (!is_array($items)) { return []; }
                foreach (array_slice($items, 0, self::COMMERCIAL_WORK_LIMIT) as $item) {
                    if (!is_array($item) || !is_string($item['requestUuid'] ?? null)
                        || !self::requestUuid($item['requestUuid'])) { continue; }
                    if (FastDeploy\BillingLink::isLinked($item)) {
                        // The local service, not the platform view, names
                        // the client whose context will be loaded.
                        $item = FastDeploy\BillingLink::project($item, $this->serverId, $this->installationId);
                        if ($item !== null) { $result[] = $item; }
                        continue;
                    }
                    if ($this->linkedOnly) { continue; }
                    if (!is_string($item['installationId'] ?? null)
                        || !hash_equals($this->installationId, $item['installationId'])) { continue; }
                    $result[] = $item;
                }
                $next = $response['nextCursor'] ?? null;
                if ($next === null) {
                    $this->pendingCommercialCursor = '';
                    return $result;
                }
                if (!is_string($next) || !self::requestUuid($next)) { return []; }
                $cursor = $next;
            }
            $this->pendingCommercialCursor = $cursor;
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Advances after a complete attempted pass. Individual transient failures
     * remain eligible in the next full cursor cycle, so one bad request does
     * not starve later pages forever. A process crash before acknowledgement
     * repeats the same page rather than skipping it.
     */
    public function acknowledgeCommercialWork(): bool
    {
        if (!$this->serverOnly || $this->pendingCommercialCursor === null) { return false; }
        $cursor = $this->pendingCommercialCursor;
        try {
            $saved = self::saveCommercialCursor($this->serverId, $cursor);
            if ($saved) { $this->pendingCommercialCursor = null; }
            return $saved;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{installation_id:string,external_client_id:string} */
    private function scope(): array
    {
        return ['installation_id' => $this->installationId, 'external_client_id' => (string) $this->clientId];
    }

    /**
     * One page of the partner's migrations waiting for a billing link.
     *
     * @return array{items:list<array<string,mixed>>,nextCursor:?string}|null
     */
    public function billingQueue(?string $cursor = null, int $limit = self::BILLING_QUEUE_LIMIT): ?array
    {
        $query = ['limit' => max(1, min(self::BILLING_QUEUE_LIMIT, $limit))];
        if ($cursor !== null && $cursor !== '') {
            if (!self::requestUuid($cursor)) { return null; }
            $query['cursor'] = strtolower($cursor);
        }
        try {
            $response = $this->client->get('/migrations/billing-queue?' . http_build_query($query));
        } catch (\Throwable) {
            return null;
        }
        $items = $response['items'] ?? ($response === [] ? [] : null);
        if (!is_array($items)) { return null; }
        $next = $response['next_cursor'] ?? $response['nextCursor'] ?? null;
        if ($next !== null && (!is_string($next) || !self::requestUuid($next))) { return null; }
        return [
            'items' => array_values(array_filter(array_slice($items, 0, self::BILLING_QUEUE_LIMIT), 'is_array')),
            'nextCursor' => $next,
        ];
    }

    /**
     * Links a migration to the native service that bills it. The same
     * service always sends the same idempotency key.
     *
     * @param array{email:string,first_name:string,last_name:string,company_name?:string} $owner
     * @return array<string,mixed>
     */
    public function linkBilling(string $requestUuid, int $clientId, int $serviceId, array $owner): array
    {
        if (!self::requestUuid($requestUuid) || $clientId <= 0 || $serviceId <= 0) {
            throw new \InvalidArgumentException('Vínculo de cobrança inválido.');
        }
        $requestUuid = strtolower($requestUuid);
        return $this->client->post('/migrations/' . rawurlencode($requestUuid) . '/billing-link', [
            'installation_id' => $this->installationId,
            'whmcs_client_id' => $clientId,
            'whmcs_service_id' => $serviceId,
            'owner' => $owner,
        ], ['Idempotency-Key' => 'whmcs-billing-link-' . $this->installationId . '-' . $requestUuid . '-' . $serviceId]);
    }

    /**
     * Asks the account holder to confirm the account in this client area.
     * The base URL is how the platform builds the confirmation link.
     *
     * @return array<string,mixed>
     */
    public function requireClaim(string $requestUuid, string $clientAreaUrl): array
    {
        if (!self::requestUuid($requestUuid) || preg_match('#^https://[^\s?\#@]+$#Di', $clientAreaUrl) !== 1) {
            throw new \InvalidArgumentException('Confirmação de conta inválida.');
        }
        $requestUuid = strtolower($requestUuid);
        return $this->client->post('/migrations/' . rawurlencode($requestUuid) . '/billing-link/claim-required', [
            'installation_id' => $this->installationId,
            'client_area_url' => $clientAreaUrl,
        ], ['Idempotency-Key' => 'whmcs-billing-claim-' . $this->installationId . '-' . $requestUuid]);
    }

    /**
     * Resolves an account confirmation token. It names a request only; the
     * owner always comes from the logged-in WHMCS client.
     *
     * @return array<string,mixed>
     */
    public function verifyClaim(string $token): array
    {
        if (preg_match('/^[A-Za-z0-9._~-]{16,256}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Confirmação de conta inválida.');
        }
        return $this->client->post('/migrations/claims/verify', ['token' => $token]);
    }

    /** @return array<string,mixed>|null */
    private function accept(array $detail): ?array
    {
        if (FastDeploy\BillingLink::isLinked($detail)) {
            return $this->billingLinks
                ? FastDeploy\BillingLink::project($detail, $this->serverId, $this->installationId, $this->clientId > 0 ? $this->clientId : null)
                : null;
        }
        return $this->owns($detail) ? $detail : null;
    }

    private function owns(array $detail): bool
    {
        $installation = $detail['installationId'] ?? $detail['installation_id'] ?? null;
        $clientId = $detail['externalClientId'] ?? $detail['external_client_id'] ?? null;
        return is_string($installation) && hash_equals($this->installationId, $installation)
            && filter_var($clientId, FILTER_VALIDATE_INT) !== false && (int) $clientId === $this->clientId;
    }

    private static function requestUuid(string $value): bool
    {
        return preg_match(self::REQUEST_UUID, $value) === 1;
    }

    private static function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Chave de idempotência inválida.');
        }
        return $value;
    }

    private static function storedInstallationId(int $serverId): ?string
    {
        if (!class_exists(Capsule::class)) {
            return null;
        }
        $setting = self::INSTALLATION_SETTING_PREFIX . $serverId;
        try {
            return Capsule::connection()->transaction(static function () use ($setting, $serverId): ?string {
                // tbladdonmodules has no unique key for a missing setting. A
                // row lock on the canonical server serializes first creation.
                $server = Capsule::table('tblservers')->where('id', $serverId)->lockForUpdate()->first();
                if ($server === null) {
                    return null;
                }
                $row = Capsule::table('tbladdonmodules')
                    ->where('module', 'bladecp')->where('setting', $setting)->lockForUpdate()->first();
                $existing = trim((string) ($row->value ?? ''));
                if (preg_match(self::REQUEST_UUID, $existing) === 1) {
                    return strtolower($existing);
                }
                if ($row !== null) {
                    // Replacing a corrupt persisted installation would orphan
                    // prior requests, so a configured row fails closed.
                    return null;
                }
                $uuid = self::uuid();
                Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $uuid]);
                return $uuid;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return string|false Empty string is the first page; false fails closed. */
    private static function commercialCursor(int $serverId): string|false
    {
        if (!class_exists(Capsule::class) || $serverId <= 0) { return false; }
        try {
            $value = trim((string) Capsule::table('tbladdonmodules')->where('module', 'bladecp')
                ->where('setting', self::COMMERCIAL_CURSOR_SETTING_PREFIX . $serverId)->value('value'));
            return $value === '' ? '' : (self::requestUuid($value) ? strtolower($value) : false);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function saveCommercialCursor(int $serverId, string $cursor): bool
    {
        if (!class_exists(Capsule::class) || $serverId <= 0 || ($cursor !== '' && !self::requestUuid($cursor))) {
            return false;
        }
        $setting = self::COMMERCIAL_CURSOR_SETTING_PREFIX . $serverId;
        try {
            return Capsule::connection()->transaction(static function () use ($serverId, $setting, $cursor): bool {
                $server = Capsule::table('tblservers')->where('id', $serverId)->lockForUpdate()->first();
                if ($server === null) { return false; }
                $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
                if ($query->exists()) { $query->update(['value' => $cursor]); }
                else { Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $cursor]); }
                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
