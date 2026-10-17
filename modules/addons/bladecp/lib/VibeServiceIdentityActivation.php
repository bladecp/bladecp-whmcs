<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Functions\Vibe\ServiceSingleSignOn;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Establishes a Vibe identity only for an already-paid standalone Pro service.
 * Browser input merely selects a service. The owner, product, plan, server and
 * pending state are resolved again from WHMCS before both the intent and save.
 */
final class VibeServiceIdentityActivation
{
    private const LOCK_TIMEOUT_SECONDS = 5;
    private static ?int $persistenceFailureAfterForTests = null;

    /** Persist only the result of the authenticated exchange for an active service. */
    public static function finalizeActiveIdentityExchange(int $serviceId, int $clientId, array $exchange): array
    {
        $identity = IdentityHandoff::currentIdentity();
        $accountId = $exchange['accountId'] ?? null;
        $version = filter_var($exchange['bindingVersion'] ?? null, FILTER_VALIDATE_INT);
        if (($identity['clientId'] ?? null) !== $clientId || ($exchange['status'] ?? '') !== 'ready'
            || !is_string($accountId) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $accountId) !== 1
            || $version === false || $version <= 0) {
            return self::error(ClientCopy::text('client-flows', 'binding_unconfirmed'));
        }
        $release = self::acquireServiceLock($serviceId);
        if ($release === null) { return self::error(ClientCopy::text('client-flows', 'binding_unregistered')); }
        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null || strtolower((string) $service->domainstatus) !== 'active') {
                return self::error(ClientCopy::text('client-flows', 'service_must_be_active'));
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', (int) $service->packageid)->first();
            if ($product === null || $product->servertype !== 'bladecp' || $product->configoption1 !== 'vibe') {
                return self::error(ClientCopy::text('client-flows', 'product_unconfirmed'));
            }
            $existing = self::accountIdForService($serviceId);
            $existingVersion = self::customFieldValueForService($serviceId, 'BladeCP Identity Binding Version');
            if (($existing !== null && !hash_equals($existing, $accountId))
                || ($existingVersion !== null && (filter_var($existingVersion, FILTER_VALIDATE_INT) === false || (int) $existingVersion > $version))) {
                return self::error(ClientCopy::text('client-flows', 'binding_review'));
            }
            $tier = strtolower(trim((string) ($product->configoption4 ?? '')));
            if (!in_array($tier, ['free', 'pro', 'builder', 'team', 'enterprise'], true)) {
                return self::error(ClientCopy::text('client-flows', 'plan_unconfirmed'));
            }
            if (!self::persistLinkAtomically($serviceId, (int) $product->id, $accountId, $version, $tier)) {
                return self::error(ClientCopy::text('client-flows', 'binding_unregistered'));
            }
            VibeWelcomeQueue::enqueue($serviceId);
            return ['ok' => true, 'serviceId' => $serviceId];
        } catch (\Throwable) {
            return self::error(ClientCopy::text('client-flows', 'binding_unregistered'));
        } finally { $release(); }
    }
    /**
     * @return array{serviceId:int,planUuid:string,client:Client,params:array<string,mixed>}|null
     */
    public static function identityContextForService(int $serviceId, int $clientId): ?array
    {
        if ($serviceId <= 0 || $clientId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblproducts')) {
                return null;
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'userid', 'packageid', 'server', 'domainstatus'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null
                || strcasecmp(trim((string) ($service->domainstatus ?? '')), 'Pending') !== 0
                || !self::hasPaidInvoiceForService($service)
                || self::accountIdForService($serviceId) !== null) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', (int) ($service->packageid ?? 0))->first();
            if ($product === null
                || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
                || strtolower(trim((string) ($product->configoption4 ?? ''))) !== 'pro') {
                return null;
            }
            $catalogPlanId = trim((string) ($product->configoption2 ?? ''));
            $serverId = self::positiveId($service->server ?? null);
            if ($catalogPlanId === '' || $serverId === null) {
                return null;
            }
            $params = ServerConfig::paramsForServerId($serverId);
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

    /**
     * @param array<string,mixed> $exchange
     * @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int}
     */
    public static function finalizeIdentityExchange(int $serviceId, int $clientId, array $exchange, bool $deferSso = false): array
    {
        $accountId = trim((string) ($exchange['accountId'] ?? ''));
        $bindingVersion = filter_var($exchange['bindingVersion'] ?? null, FILTER_VALIDATE_INT);
        if ($accountId === '' || $bindingVersion === false || (int) $bindingVersion <= 0) {
            return self::error(ClientCopy::text('client-flows', 'vibe_account_unconfirmed'));
        }
        $release = self::acquireServiceLock($serviceId);
        if ($release === null) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_incomplete'));
        }
        try {
            $existingAccount = self::accountIdForService($serviceId);
            if ($existingAccount !== null) {
                $existingBinding = filter_var(self::customFieldValueForService($serviceId, 'BladeCP Identity Binding Version'), FILTER_VALIDATE_INT);
                if ($existingAccount !== $accountId || $existingBinding === false || (int) $existingBinding !== (int) $bindingVersion) {
                    return self::error(ClientCopy::text('client-flows', 'vibe_account_unconfirmed'));
                }
                return self::resumeProvisioningLocked($serviceId, $clientId, $deferSso);
            }
            $context = self::identityContextForService($serviceId, $clientId);
            if ($context === null || !self::persistLinkAtomically($serviceId, (int) $context['params']['pid'], $accountId, (int) $bindingVersion)) {
                return self::error(ClientCopy::text('client-flows', 'vibe_account_unregistered'));
            }
            return self::resumeProvisioningLocked($serviceId, $clientId, $deferSso);
        } finally {
            $release();
        }
    }

    /**
     * Resumes only a link that was already persisted for this exact pending,
     * paid Pro service. This is the retry path if AcceptOrder was interrupted.
     *
     * @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int}
     */
    public static function resumeProvisioning(int $serviceId, int $clientId): array
    {
        $release = self::acquireServiceLock($serviceId);
        if ($release === null) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_incomplete'));
        }
        try {
            return self::resumeProvisioningLocked($serviceId, $clientId);
        } finally {
            $release();
        }
    }

    /** @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int} */
    private static function resumeProvisioningLocked(int $serviceId, int $clientId, bool $deferSso = false): array
    {
        $linked = self::linkedServiceContextForService($serviceId, $clientId, false);
        if ($linked === null) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_incomplete'));
        }
        if (!self::acceptPendingOrder($serviceId)) {
            return self::error(ClientCopy::text('client-flows', 'vibe_service_incomplete'));
        }
        VibeWelcomeQueue::enqueue($serviceId);
        return $deferSso ? ['ok' => true, 'serviceId' => $serviceId] : self::issueSso($serviceId, $clientId);
    }

    /** Test-only deterministic failure injection for transactional persistence. */
    private static function setPersistenceFailureAfterForTests(?int $writes): void
    {
        self::$persistenceFailureAfterForTests = $writes === null ? null : max(0, $writes);
    }

    /** @return array{serviceId:int,accountId:string}|null */
    public static function linkedPendingContextForService(int $serviceId, int $clientId): ?array
    {
        return self::linkedServiceContextForService($serviceId, $clientId, false);
    }

    /** @return array{serviceId:int,accountId:string,status:string}|null */
    private static function linkedServiceContextForService(int $serviceId, int $clientId, bool $allowActive): ?array
    {
        if ($serviceId <= 0 || $clientId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)->where('userid', $clientId)->first();
            $status = strtolower(trim((string) ($service->domainstatus ?? '')));
            if ($service === null
                || !in_array($status, $allowActive ? ['pending', 'active'] : ['pending'], true)
                || !self::hasPaidInvoiceForService($service)) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', (int) ($service->packageid ?? 0))->first();
            $accountId = self::accountIdForService($serviceId);
            $bindingVersion = filter_var(self::customFieldValueForService($serviceId, 'BladeCP Identity Binding Version'), FILTER_VALIDATE_INT);
            if ($product === null || $accountId === null
                || $bindingVersion === false || (int) $bindingVersion <= 0
                || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
                || strtolower(trim((string) ($product->configoption4 ?? ''))) !== 'pro'
                || self::positiveId($service->server ?? null) === null) {
                return null;
            }
            return ['serviceId' => $serviceId, 'accountId' => $accountId, 'status' => $status];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ok:bool,redirectTo?:string,error?:string,serviceId?:int} */
    private static function issueSso(int $serviceId, int $clientId): array
    {
        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null || strcasecmp(trim((string) ($service->domainstatus ?? '')), 'Active') !== 0) {
                return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', (int) $service->packageid)->first();
            $serverId = self::positiveId($service->server ?? null);
            $accountId = self::accountIdForService($serviceId);
            if ($product === null || $serverId === null || $accountId === null) {
                return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
            }
            $params = ServerConfig::paramsForServerId($serverId);
            if ($params === null) {
                return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
            }
            $sso = ServiceSingleSignOn::run($params + [
                'serviceid' => $serviceId,
                'pid' => (int) $product->id,
                'customfields' => ['BladeCP Vibe Account ID' => $accountId],
            ]);
            $redirectTo = $sso['redirectTo'] ?? null;
            if (($sso['success'] ?? false) !== true || !is_string($redirectTo) || $redirectTo === '') {
                return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
            }
            return ['ok' => true, 'redirectTo' => $redirectTo, 'serviceId' => $serviceId];
        } catch (\Throwable) {
            return self::error(ClientCopy::text('client-flows', 'vibe_ready_access_failed'));
        }
    }

    private static function persistLinkAtomically(int $serviceId, int $productId, string $accountId, int $bindingVersion, string $tier = 'pro'): bool
    {
        if ($serviceId <= 0 || $productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            \WHMCS\Database\Capsule::connection()->transaction(static function () use ($serviceId, $productId, $accountId, $bindingVersion, $tier): void {
                $schema = \WHMCS\Database\Capsule::schema();
                if (!$schema->hasTable('tblcustomfields') || !$schema->hasTable('tblcustomfieldsvalues')) {
                    throw new \RuntimeException('custom_fields_unavailable');
                }
                $columns = array_flip($schema->getColumnListing('tblcustomfields'));
                $values = [
                    'BladeCP Vibe Account ID' => $accountId,
                    'BladeCP External Service ID' => (string) $serviceId,
                    'BladeCP Identity Binding Version' => (string) $bindingVersion,
                    'BladeCP Product Type' => 'vibe',
                    'BladeCP Vibe Plan Tier' => $tier,
                ];
                foreach ($values as $name => $value) {
                    $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                        ->where('type', 'product')->where('relid', $productId)->where('fieldname', $name)->first();
                    $fieldId = $field === null ? 0 : (int) ($field->id ?? 0);
                    if ($fieldId <= 0) {
                        $fieldId = (int) \WHMCS\Database\Capsule::table('tblcustomfields')->insertGetId(array_intersect_key([
                            'type' => 'product', 'relid' => $productId, 'fieldname' => $name,
                            'fieldtype' => 'text', 'description' => 'BladeCP Vibe service correlation field.',
                            'required' => '', 'showorder' => '', 'showinvoice' => '', 'sortorder' => 0, 'adminonly' => 'on',
                        ], $columns));
                    }
                    if ($fieldId <= 0) {
                        throw new \RuntimeException('custom_field_create_failed');
                    }
                    if (self::$persistenceFailureAfterForTests !== null) {
                        if (self::$persistenceFailureAfterForTests === 0) {
                            throw new \RuntimeException('injected_persistence_failure');
                        }
                        self::$persistenceFailureAfterForTests--;
                    }
                    $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $fieldId)->where('relid', $serviceId)->first();
                    if ($existing === null) {
                        \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert([
                            'fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value,
                        ]);
                    } else {
                        \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                            ->where('id', (int) $existing->id)->update(['value' => $value]);
                    }
                }
            });
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            self::$persistenceFailureAfterForTests = null;
        }
    }

    /** @return (\Closure():void)|null */
    private static function acquireServiceLock(int $serviceId, ?int $timeoutSeconds = null): ?\Closure
    {
        if ($serviceId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $connection = \WHMCS\Database\Capsule::connection();
            $driver = method_exists($connection, 'getDriverName') ? strtolower((string) $connection->getDriverName()) : '';
            $timeout = max(0, min(self::LOCK_TIMEOUT_SECONDS, $timeoutSeconds ?? self::LOCK_TIMEOUT_SECONDS));
            if ($driver === 'mysql') {
                // Field definitions are shared by every Vibe Pro service in a
                // product. A single rare-flow lock avoids a cross-service
                // select-then-insert race without relying on a unique index.
                $name = 'bladecp_vp_identity_global';
                $rows = $connection->select('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
                $acquired = is_object($row) ? ($row->acquired ?? null) : (is_array($row) ? ($row['acquired'] ?? null) : null);
                if ((int) $acquired !== 1) {
                    return null;
                }
                return static function () use ($connection, $name): void {
                    try { $connection->select('SELECT RELEASE_LOCK(?) AS released', [$name]); } catch (\Throwable) {}
                };
            }
            if ($driver !== 'sqlite' && !(PHP_SAPI === 'cli' && $driver === '')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bladecp-vibe-pro-identity-global.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return null;
        }
        @chmod($path, 0600);
        $deadline = microtime(true) + ($timeoutSeconds === null ? self::LOCK_TIMEOUT_SECONDS : max(0, min(self::LOCK_TIMEOUT_SECONDS, $timeoutSeconds)));
        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return static function () use ($handle): void { @flock($handle, LOCK_UN); @fclose($handle); };
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        @fclose($handle);
        return null;
    }

    private static function acceptPendingOrder(int $serviceId): bool
    {
        if ($serviceId <= 0 || !function_exists('localAPI')) {
            return false;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblorders')) {
                return false;
            }
            $columns = array_flip($schema->getColumnListing('tblhosting'));
            if (!isset($columns['orderid'])) {
                return false;
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            $orderId = $service === null ? null : self::positiveId($service->orderid ?? null);
            if ($orderId === null) {
                return false;
            }
            $order = \WHMCS\Database\Capsule::table('tblorders')->where('id', $orderId)->first();
            if ($order === null) {
                return false;
            }
            $status = strtolower(trim((string) ($order->status ?? '')));
            if ($status === 'active') {
                return true;
            }
            if ($status !== 'pending') {
                return false;
            }
            $accepted = localAPI('AcceptOrder', [
                'orderid' => $orderId,
                'autosetup' => false,
                'sendemail' => false,
            ]);
            return ($accepted['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A Pending service alone is not commercial authority. Require its exact
     * WHMCS order plus an invoice item tied to this service on a paid invoice.
     */
    private static function hasPaidInvoiceForService(object $service): bool
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            foreach (['tblorders', 'tblinvoices', 'tblinvoiceitems'] as $table) {
                if (!$schema->hasTable($table)) {
                    return false;
                }
            }
            $serviceId = self::positiveId($service->id ?? null);
            $orderId = self::positiveId($service->orderid ?? null);
            $orderColumns = array_flip($schema->getColumnListing('tblorders'));
            $invoiceColumns = array_flip($schema->getColumnListing('tblinvoices'));
            $itemColumns = array_flip($schema->getColumnListing('tblinvoiceitems'));
            if ($serviceId === null || $orderId === null
                || !isset($orderColumns['invoiceid'], $invoiceColumns['status'], $itemColumns['invoiceid'], $itemColumns['relid'], $itemColumns['type'])) {
                return false;
            }
            $order = \WHMCS\Database\Capsule::table('tblorders')->where('id', $orderId)->first();
            $invoiceId = $order === null ? null : self::positiveId($order->invoiceid ?? null);
            if ($invoiceId === null) {
                return false;
            }
            $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            if ($invoice === null || strcasecmp(trim((string) ($invoice->status ?? '')), 'Paid') !== 0) {
                return false;
            }
            if (isset($invoiceColumns['userid'])
                && (int) ($invoice->userid ?? 0) !== (int) ($service->userid ?? 0)) {
                return false;
            }
            return \WHMCS\Database\Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoiceId)->where('relid', $serviceId)->where('type', 'Hosting')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function accountIdForService(int $serviceId): ?string
    {
        return self::customFieldValueForService($serviceId, 'BladeCP Vibe Account ID');
    }

    private static function customFieldValueForService(int $serviceId, string $fieldName): ?string
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblcustomfields') || !$schema->hasTable('tblcustomfieldsvalues')) {
                return null;
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'product')->where('relid', (int) $service->packageid)
                ->where('fieldname', $fieldName)->first();
            if ($field === null) {
                return null;
            }
            $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', (int) $field->id)->where('relid', $serviceId)->first();
            $fieldValue = trim((string) ($value->value ?? ''));
            return $fieldValue === '' ? null : $fieldValue;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }

    /** @return array{ok:false,error:string} */
    private static function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
