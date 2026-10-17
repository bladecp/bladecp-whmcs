<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Functions\Vibe\ServiceSingleSignOn;
use WHMCS\Module\Server\Bladecp\Helpers\SaleGuard;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibeEmailVerification;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceFields;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Activates the canonical Vibe Free offer for the currently authenticated
 * WHMCS customer without exposing the shopping cart.
 *
 * The WHMCS service remains the durable commercial record. The transaction
 * locks the customer before looking up or creating that record, so concurrent
 * browser tabs converge on one service. Customer API idempotency remains the
 * final cross-channel grant protection.
 */
final class VibeFreeActivation
{
    private const ACTIVE_STATUSES = ['Pending', 'Active'];
    private const PROVISION_LOCK_TIMEOUT_SECONDS = 5;

    /**
     * @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int}
     */
    public static function activateForClient(int $clientId, string $traceId = '', ?string $launchToken = null): array
    {
        $traceId = VibeFreeTrace::id($traceId);
        if ($clientId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            self::trace('claim_finish', $traceId, 'invalid_service');
            return self::error(ClientCopy::text('client-flows', 'account_unconfirmed'));
        }

        self::trace('claim_start', $traceId);
        try {
            $claim = \WHMCS\Database\Capsule::connection()->transaction(
                static fn (): array => self::claimService($clientId)
            );
        } catch (\Throwable) {
            self::trace('claim_finish', $traceId, 'exception');
            return self::error(ClientCopy::text('client-flows', 'vibe_access_unprepared'));
        }

        if (($claim['ok'] ?? false) !== true) {
            self::trace('claim_finish', $traceId, 'rejected');
            return $claim;
        }

        $serviceId = (int) ($claim['serviceId'] ?? 0);
        if ($serviceId <= 0) {
            self::trace('claim_finish', $traceId, 'invalid_service');
            return self::error(ClientCopy::text('client-flows', 'vibe_service_not_found'));
        }
        self::trace('claim_finish', $traceId, (string) ($claim['claimOutcome'] ?? 'existing'));

        if (!self::ensureProvisioned($serviceId, $traceId)) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_not_ready'));
        }

        return self::issueSso($serviceId, $launchToken);
    }

    /**
     * Creates or reuses only the commercial Free service. The global identity
     * and Vibe account are deliberately resolved later by the identity-link
     * exchange, never from the WHMCS email or this browser request.
     *
     * @return array{ok:bool,error?:string,serviceId?:int,planUuid?:string,client?:Client,params?:array<string,mixed>}
     */
    public static function prepareForIdentity(int $clientId, string $traceId = ''): array
    {
        $traceId = VibeFreeTrace::id($traceId);
        if ($clientId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            self::trace('claim_finish', $traceId, 'invalid_service');
            return self::error(ClientCopy::text('client-flows', 'account_unconfirmed'));
        }

        self::trace('claim_start', $traceId);
        try {
            $claim = \WHMCS\Database\Capsule::connection()->transaction(
                static fn (): array => self::claimService($clientId)
            );
        } catch (\Throwable) {
            self::trace('claim_finish', $traceId, 'exception');
            return self::error(ClientCopy::text('client-flows', 'vibe_access_unprepared'));
        }
        if (($claim['ok'] ?? false) !== true) {
            self::trace('claim_finish', $traceId, 'rejected');
            return $claim;
        }

        $serviceId = (int) ($claim['serviceId'] ?? 0);
        $context = $serviceId > 0 ? self::identityContext($serviceId) : null;
        if ($context === null) {
            self::trace('claim_finish', $traceId, 'invalid_service');
            return self::error(ClientCopy::text('client-flows', 'vibe_context_unconfirmed'));
        }
        self::trace('claim_finish', $traceId, (string) ($claim['claimOutcome'] ?? 'existing'));

        return ['ok' => true] + $context;
    }

    /**
     * Persists only the account returned by the identity-link exchange, then
     * opens its usual account-scoped SSO. No ModuleCreate runs in this path.
     *
     * @param array<string,mixed> $exchange
     * @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int}
     */
    public static function finalizeIdentityExchange(int $serviceId, array $exchange, string $traceId = '', ?string $launchToken = null, bool $deferSso = false): array
    {
        $traceId = VibeFreeTrace::id($traceId);
        $accountId = trim((string) ($exchange['accountId'] ?? ''));
        $bindingVersion = filter_var($exchange['bindingVersion'] ?? null, FILTER_VALIDATE_INT);
        if ($serviceId <= 0 || $accountId === '' || $bindingVersion === false || (int) $bindingVersion <= 0) {
            return self::error(ClientCopy::text('client-flows', 'vibe_account_unconfirmed'));
        }
        $context = self::identityContext($serviceId);
        if ($context === null || !self::acceptPendingOrder($serviceId, $traceId)) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_incomplete'));
        }

        $params = $context['params'];
        ServiceProperties::save($params, [
            'BladeCP Vibe Account ID' => $accountId,
            'BladeCP External Service ID' => (string) $serviceId,
            'BladeCP Identity Binding Version' => (string) $bindingVersion,
            'BladeCP Product Type' => 'vibe',
            'Last Sync' => date('Y-m-d H:i:s'),
        ]);
        if (!VibeServiceFields::persist($params, [
            'BladeCP Vibe Account ID' => $accountId,
            'BladeCP External Service ID' => (string) $serviceId,
            'BladeCP Identity Binding Version' => (string) $bindingVersion,
            'BladeCP Vibe Plan Tier' => 'free',
        ])) {
            return self::error(ClientCopy::text('client-flows', 'vibe_account_unregistered'));
        }

        VibeWelcomeQueue::enqueue($serviceId);
        return $deferSso ? ['ok' => true, 'serviceId' => $serviceId] : self::issueSso($serviceId, $launchToken);
    }

    /** @return array{serviceId:int,planUuid:string,client:Client,params:array<string,mixed>}|null */
    public static function identityContextForService(int $serviceId): ?array
    {
        return self::identityContext($serviceId);
    }

    /**
     * Acquires the commercial service while the customer row is locked.
     *
     * @return array{ok:bool,error?:string,serviceId?:int,claimOutcome?:string}
     */
    private static function claimService(int $clientId): array
    {
        $client = \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', $clientId)
            ->lockForUpdate()
            ->first();
        if ($client === null) {
            return self::error(ClientCopy::text('client-flows', 'client_account_unconfirmed'));
        }
        $emailVerification = VibeEmailVerification::evaluateForClient($client);
        if (($emailVerification['assurance'] ?? null) === null) {
            return self::error(!empty($emailVerification['confirmationRequired'])
                ? ClientCopy::text('client-flows', 'email_unverified')
                : ClientCopy::text('client-flows', 'email_policy_unknown'));
        }

        $product = self::canonicalFreeProduct();
        if ($product === null) {
            return self::error(ClientCopy::text('client-flows', 'free_offer_unavailable'));
        }

        $availability = SaleGuard::checkProductId((int) $product->id);
        if ($availability === null || ($availability['eligible'] ?? false) !== true) {
            return self::error(ClientCopy::text('client-flows', 'free_offer_unavailable'));
        }

        $service = self::existingService($clientId);
        if ($service !== null) {
            $status = trim((string) ($service->domainstatus ?? ''));
            if (in_array($status, self::ACTIVE_STATUSES, true)) {
                return ['ok' => true, 'serviceId' => (int) $service->id, 'claimOutcome' => 'existing'];
            }

            // Never create a second Free service after this customer has had
            // one. The Customer API applies the same rule across channels.
            return self::error(ClientCopy::text('client-flows', 'free_history_exists'));
        }

        return self::createFreeOrder($client, $product);
    }

    /** Read-only offer discovery shared with the optional homepage card. @return object|null */
    public static function canonicalFreeProduct(): ?object
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblproducts')) {
                return null;
            }
            $columns = array_flip($schema->getColumnListing('tblproducts'));
            foreach (['id', 'servertype', 'configoption1', 'configoption2', 'configoption4', 'paytype', 'hidden'] as $column) {
                if (!isset($columns[$column])) {
                    return null;
                }
            }

            $matches = [];
            foreach (\WHMCS\Database\Capsule::table('tblproducts')->get() as $product) {
                if ((string) ($product->servertype ?? '') !== 'bladecp'
                    || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
                    || strtolower(trim((string) ($product->configoption4 ?? ''))) !== 'free'
                    || strtolower(trim((string) ($product->paytype ?? ''))) !== 'free'
                    || (int) ($product->hidden ?? 0) !== 0
                    || trim((string) ($product->configoption2 ?? '')) === '') {
                    continue;
                }
                if (isset($columns['retired']) && (int) ($product->retired ?? 0) !== 0) {
                    continue;
                }
                $matches[] = $product;
            }

            return count($matches) === 1 ? $matches[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return object|null */
    private static function existingService(int $clientId): ?object
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting')) {
                return null;
            }
            $columns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'userid', 'packageid', 'domainstatus'] as $column) {
                if (!isset($columns[$column])) {
                    return null;
                }
            }

            foreach (\WHMCS\Database\Capsule::table('tblhosting')
                ->where('userid', $clientId)
                ->orderBy('id')
                ->get() as $service) {
                $product = \WHMCS\Database\Capsule::table('tblproducts')
                    ->where('id', (int) ($service->packageid ?? 0))
                    ->first();
                if ($product !== null
                    && (string) ($product->servertype ?? '') === 'bladecp'
                    && strtolower(trim((string) ($product->configoption1 ?? ''))) === 'vibe'
                    && strtolower(trim((string) ($product->configoption4 ?? ''))) === 'free') {
                    return $service;
                }
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ok:bool,error?:string,serviceId?:int,claimOutcome?:string} */
    private static function createFreeOrder(object $client, object $product): array
    {
        if (!function_exists('localAPI')) {
            return self::error(ClientCopy::text('client-flows', 'free_activation_failed'));
        }
        $clientId = (int) ($client->id ?? 0);
        $productId = (int) ($product->id ?? 0);
        $paymentMethod = self::paymentMethod($product);
        if ($clientId <= 0 || $productId <= 0 || $paymentMethod === null) {
            return self::error(ClientCopy::text('client-flows', 'free_activation_unprepared'));
        }

        try {
            $order = localAPI('AddOrder', [
                'clientid' => $clientId,
                'paymentmethod' => $paymentMethod,
                'pid' => [$productId],
                'qty' => [1],
                'noinvoice' => true,
                'noinvoiceemail' => true,
                'noemail' => true,
            ]);
        } catch (\Throwable) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_unregistered'));
        }

        if (($order['result'] ?? '') !== 'success') {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_unregistered'));
        }
        $serviceId = self::firstId($order['serviceids'] ?? null);
        if ($serviceId === null) {
            return self::error(ClientCopy::text('client-flows', 'vibe_invalid_confirmation'));
        }
        // Do not accept the order while holding tblclients. AddOrder creates
        // the durable coordination record under that lock; a retry will find
        // the same Pending service and continue acceptance below.
        return ['ok' => true, 'serviceId' => $serviceId, 'claimOutcome' => 'created'];
    }

    private static function ensureProvisioned(int $serviceId, string $traceId): bool
    {
        if (!self::acceptPendingOrder($serviceId, $traceId)) {
            return false;
        }
        if (self::accountIdForService($serviceId) !== null) {
            self::trace('module_create_finish', $traceId, 'account_present');
            return true;
        }
        $releaseLock = self::acquireProvisioningLock($serviceId);
        if ($releaseLock === null) {
            self::trace('module_create_finish', $traceId, 'unavailable', 'api_unavailable');
            return false;
        }
        try {
            // A parallel request may have provisioned the service while this
            // request waited for the cross-request lock.
            if (self::accountIdForService($serviceId) !== null) {
                self::trace('module_create_finish', $traceId, 'account_present');
                return true;
            }
            if (!function_exists('localAPI')) {
                self::trace('module_create_finish', $traceId, 'unavailable', 'api_unavailable');
                return false;
            }

            self::trace('module_create_start', $traceId);
            try {
                $result = localAPI('ModuleCreate', ['serviceid' => $serviceId]);
            } catch (\Throwable $exception) {
                self::trace('module_create_finish', $traceId, 'exception', self::moduleCreateFailureReason($exception->getMessage()));
                return false;
            }

            if (($result['result'] ?? '') !== 'success') {
                self::trace('module_create_finish', $traceId, 'api_error', self::moduleCreateFailureReason($result));
                return false;
            }
            if (self::accountIdForService($serviceId) === null) {
                self::trace('module_create_finish', $traceId, 'account_missing', 'persistence');
                return false;
            }
            self::trace('module_create_finish', $traceId, 'success');
            return true;
        } finally {
            $releaseLock();
        }
    }

    /** @return (\Closure():void)|null */
    private static function acquireProvisioningLock(int $serviceId, ?int $timeoutSeconds = null): ?\Closure
    {
        if ($serviceId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        $timeout = max(0, min(self::PROVISION_LOCK_TIMEOUT_SECONDS, $timeoutSeconds ?? self::PROVISION_LOCK_TIMEOUT_SECONDS));

        try {
            $connection = \WHMCS\Database\Capsule::connection();
            $driver = method_exists($connection, 'getDriverName')
                ? strtolower(trim((string) $connection->getDriverName()))
                : '';
            if ($driver === 'mysql') {
                $name = 'bladecp_vf_' . substr(hash('sha256', (string) $serviceId), 0, 32);
                $rows = $connection->select(
                    'SELECT GET_LOCK(?, ?) AS acquired',
                    [$name, $timeout]
                );
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
                $acquired = is_object($row) ? ($row->acquired ?? null) : (is_array($row) ? ($row['acquired'] ?? null) : null);
                if ((int) $acquired !== 1) {
                    return null;
                }

                return static function () use ($connection, $name): void {
                    try {
                        $connection->select('SELECT RELEASE_LOCK(?) AS released', [$name]);
                    } catch (\Throwable) {
                        // The connection teardown also releases an advisory lock.
                    }
                };
            }

            // SQLite is used by the integration harness only. Production on
            // an unsupported driver fails closed instead of silently using a
            // node-local lock.
            if ($driver !== 'sqlite' && !(PHP_SAPI === 'cli' && $driver === '')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return self::acquireFallbackProvisioningLock($serviceId, $timeout);
    }

    /** @return (\Closure():void)|null */
    private static function acquireFallbackProvisioningLock(int $serviceId, int $timeoutSeconds): ?\Closure
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'bladecp-vibe-free-' . substr(hash('sha256', (string) $serviceId), 0, 32) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        @chmod($path, 0600);

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return static function () use ($handle): void {
                    @flock($handle, LOCK_UN);
                    @fclose($handle);
                };
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        @fclose($handle);
        return null;
    }

    /**
     * Completes a previously-created Free order outside the customer lock.
     *
     * AddOrder can succeed while AcceptOrder is interrupted. The service is
     * intentionally retained as the durable retry marker, so a later click
     * resumes the same order instead of creating another grant.
     */
    private static function acceptPendingOrder(int $serviceId, string $traceId = ''): bool
    {
        if ($serviceId <= 0 || !function_exists('localAPI')) {
            self::trace('order_accept_finish', $traceId, 'unavailable');
            return false;
        }
        self::trace('order_accept_start', $traceId);
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblorders')) {
                self::trace('order_accept_finish', $traceId, 'unavailable');
                return false;
            }
            $columns = array_flip($schema->getColumnListing('tblhosting'));
            if (!isset($columns['orderid'])) {
                self::trace('order_accept_finish', $traceId, 'unavailable');
                return false;
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                self::trace('order_accept_finish', $traceId, 'invalid_service');
                return false;
            }
            $orderId = self::positiveId($service->orderid ?? null);
            if ($orderId === null) {
                self::trace('order_accept_finish', $traceId, 'invalid_service');
                return false;
            }
            $order = \WHMCS\Database\Capsule::table('tblorders')->where('id', $orderId)->first();
            if ($order === null) {
                self::trace('order_accept_finish', $traceId, 'invalid_service');
                return false;
            }
            $status = strtolower(trim((string) ($order->status ?? '')));
            if ($status === 'active') {
                self::trace('order_accept_finish', $traceId, 'active');
                return true;
            }
            if ($status !== 'pending') {
                self::trace('order_accept_finish', $traceId, 'rejected');
                return false;
            }
            $accepted = VibeActivationDiagnostics::run('order_accept', VibeActivationDiagnostics::context($serviceId, $traceId), fn () => localAPI('AcceptOrder', [
                'orderid' => $orderId,
                // Provisioning is explicitly performed by ensureProvisioned
                // below, once this order acceptance has completed. This keeps
                // the durable order transition separate from ModuleCreate.
                'autosetup' => false,
                'sendemail' => false,
            ]));
            if (($accepted['result'] ?? '') !== 'success') {
                self::trace('order_accept_finish', $traceId, 'api_error');
                return false;
            }
            self::trace('order_accept_finish', $traceId, 'success');
            return true;
        } catch (\Throwable) {
            self::trace('order_accept_finish', $traceId, 'exception');
            return false;
        }
    }

    /** @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int} */
    private static function issueSso(int $serviceId, ?string $launchToken = null): array
    {
        $context = self::serviceContext($serviceId);
        if ($context === null) {
            return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
        }

        if ($launchToken !== null && preg_match('/^[A-Za-z0-9_-]{43}$/D', $launchToken) === 1) {
            $context['launchToken'] = $launchToken;
        }
        $sso = ServiceSingleSignOn::run($context);
        $url = $sso['redirectTo'] ?? null;
        if (($sso['success'] ?? false) !== true || !is_string($url) || $url === '') {
            return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
        }

        return ['ok' => true, 'redirectTo' => $url, 'serviceId' => $serviceId];
    }

    /** @return array<string,mixed>|null */
    private static function serviceContext(int $serviceId): ?array
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblproducts')) {
                return null;
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'packageid', 'server'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', (int) ($service->packageid ?? 0))->first();
            if ($product === null
                || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
                || strtolower(trim((string) ($product->configoption4 ?? ''))) !== 'free') {
                return null;
            }

            $serverId = (int) ($service->server ?? 0);
            $params = ServerConfig::paramsForServerId($serverId);
            $accountId = self::accountIdForService($serviceId);
            if ($params === null || $accountId === null) {
                return null;
            }

            return $params + [
                'serviceid' => $serviceId,
                'customfields' => ['BladeCP Vibe Account ID' => $accountId],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves only the server-side commercial context needed to issue or
     * exchange an identity-link intent. Nothing here comes from the browser.
     *
     * @return array{serviceId:int,planUuid:string,client:Client,params:array<string,mixed>}|null
     */
    private static function identityContext(int $serviceId): ?array
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblproducts')) {
                return null;
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'packageid', 'server'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', (int) ($service->packageid ?? 0))->first();
            if ($product === null) {
                return null;
            }
            $catalogPlanId = trim((string) ($product->configoption2 ?? ''));
            if ((string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
                || strtolower(trim((string) ($product->configoption4 ?? ''))) !== 'free'
                || $catalogPlanId === '') {
                return null;
            }
            $serverId = self::positiveId($service->server ?? null);
            $params = $serverId === null ? null : ServerConfig::paramsForServerId($serverId);
            if ($params === null) {
                return null;
            }
            $params['serviceid'] = $serviceId;
            $params['pid'] = (int) ($product->id ?? 0);
            $params['configoption2'] = $catalogPlanId;
            $planUuid = VibePlanReference::fromParams($params);
            if ($planUuid === null) {
                return null;
            }

            return [
                'serviceId' => $serviceId,
                'planUuid' => $planUuid,
                'client' => Client::fromParams($params),
                'params' => $params,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function accountIdForService(int $serviceId): ?string
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting')
                || !$schema->hasTable('tblcustomfields')
                || !$schema->hasTable('tblcustomfieldsvalues')) {
                return null;
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('relid', (int) ($service->packageid ?? 0))
                ->where('fieldname', 'BladeCP Vibe Account ID')
                ->first();
            if ($field === null) {
                return null;
            }
            $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int) ($field->id ?? 0))
                ->where('relid', $serviceId)
                ->first();
            $accountId = trim((string) ($value->value ?? ''));
            return $accountId === '' ? null : $accountId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves the first gateway that the customer would see for this product.
     *
     * A Free activation is not a customer-selected checkout, so the client
     * default gateway must not influence it. WHMCS stores the visual gateway
     * order on each `name` row. The `visible` row has its own unrelated order,
     * therefore it is an eligibility flag only.
     */
    private static function paymentMethod(object $product): ?string
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblpaymentgateways')
                || !$schema->hasTable('tblproductgroups')) {
                return null;
            }

            $gatewayColumns = array_flip($schema->getColumnListing('tblpaymentgateways'));
            foreach (['gateway', 'setting', 'value', 'order'] as $column) {
                if (!isset($gatewayColumns[$column])) {
                    return null;
                }
            }

            $groupColumns = array_flip($schema->getColumnListing('tblproductgroups'));
            foreach (['id', 'disabledgateways'] as $column) {
                if (!isset($groupColumns[$column])) {
                    return null;
                }
            }

            $groupId = filter_var($product->gid ?? null, FILTER_VALIDATE_INT);
            if ($groupId === false || (int) $groupId <= 0) {
                return null;
            }
            $group = \WHMCS\Database\Capsule::table('tblproductgroups')
                ->where('id', (int) $groupId)
                ->first();
            if ($group === null) {
                return null;
            }

            $disabled = array_fill_keys(array_filter(array_map(
                static fn (string $gateway): string => strtolower(trim($gateway)),
                explode(',', (string) ($group->disabledgateways ?? ''))
            )), true);
            $visible = [];
            $ordered = [];
            foreach (\WHMCS\Database\Capsule::table('tblpaymentgateways')
                ->whereIn('setting', ['name', 'visible'])
                ->get(['gateway', 'setting', 'value', 'order']) as $gateway) {
                $name = trim((string) ($gateway->gateway ?? ''));
                if ($name === '') {
                    continue;
                }
                $setting = strtolower(trim((string) ($gateway->setting ?? '')));
                if ($setting === 'visible') {
                    $visible[strtolower($name)] = in_array(
                        strtolower(trim((string) ($gateway->value ?? ''))),
                        ['on', '1', 'true'],
                        true
                    );
                    continue;
                }
                if ($setting === 'name') {
                    $order = filter_var($gateway->order ?? null, FILTER_VALIDATE_INT);
                    if ($order === false) {
                        continue;
                    }
                    $ordered[] = [
                        'gateway' => $name,
                        'key' => strtolower($name),
                        'order' => (int) $order,
                    ];
                }
            }

            usort($ordered, static fn (array $left, array $right): int =>
                $left['order'] <=> $right['order'] ?: $left['key'] <=> $right['key']
            );
            foreach ($ordered as $gateway) {
                if (($visible[$gateway['key']] ?? false) && !isset($disabled[$gateway['key']])) {
                    return $gateway['gateway'];
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private static function firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach ($value as $candidate) {
                $id = self::positiveId($candidate);
                if ($id !== null) {
                    return $id;
                }
            }
            return null;
        }
        foreach (explode(',', (string) $value) as $candidate) {
            $id = self::positiveId(trim($candidate));
            if ($id !== null) {
                return $id;
            }
        }
        return null;
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0
            ? (int) $value
            : null;
    }

    /** @param array<string,mixed>|string $result */
    private static function moduleCreateFailureReason(array|string $result): string
    {
        $message = is_array($result)
            ? trim((string) ($result['message'] ?? $result['error'] ?? ''))
            : trim($result);
        $normalized = strtolower($message);

        if (preg_match('/\b(plan|produto|product)\b/u', $normalized) === 1) {
            return 'plan_missing';
        }
        if (preg_match('/\b(email|e-mail|verification|confirm)\b/u', $normalized) === 1) {
            return 'email_policy';
        }
        if (preg_match('/\b(service|servi[cç]o|hosting)\b/u', $normalized) === 1) {
            return 'service_missing';
        }
        if (preg_match('/\b(owner|customer|client|partner|cliente|parceiro)\b/u', $normalized) === 1) {
            return 'owner_invalid';
        }
        if (preg_match('/\b(persist|database|storage|d1)\b/u', $normalized) === 1) {
            return 'persistence';
        }
        if (preg_match('/\b(timeout|timed out|network|connection|connect|gateway|unavailable|temporar|contatar)/u', $normalized) === 1) {
            return 'api_unavailable';
        }
        if (preg_match('/\b(contract|api|http|status|validation)\b/u', $normalized) === 1) {
            return 'api_contract';
        }

        return 'other';
    }

    private static function trace(string $event, string $traceId, ?string $outcome = null, ?string $reason = null): void
    {
        $fields = $outcome === null ? [] : ['outcome' => $outcome];
        if ($reason !== null) {
            $fields['reason'] = $reason;
        }
        VibeFreeTrace::record($event, $traceId, $fields);
    }

    /** @return array{ok:false,error:string} */
    private static function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
