<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Local attestation for an API-redeemed direct Vibe recovery. */
final class VibeContextRecoveryService
{
    /**
     * @return array{serviceId:int,productId:int}|null
     */
    public static function resolve(
        int $clientId,
        int $serviceId,
        int $serverId,
        Client $serverClient,
        string $accountId
    ): ?array {
        if ($clientId <= 0 || $serviceId <= 0 || $serverId <= 0 || $accountId === ''
            || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            foreach (['tblhosting', 'tblproducts', 'tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $columns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'userid', 'packageid', 'server', 'domainstatus'] as $column) {
                if (!isset($columns[$column])) {
                    return null;
                }
            }
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null || strtolower(trim((string) ($service->domainstatus ?? ''))) !== 'active'
                || (int) ($service->server ?? 0) !== $serverId) {
                return null;
            }
            $productId = (int) ($service->packageid ?? 0);
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $productId)->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $serviceClient = ServerConfig::clientForServerId($serverId);
            if ($serviceClient === null || !hash_equals($serverClient->baseUrl(), $serviceClient->baseUrl())) {
                return null;
            }
            $storedAccount = self::customFieldValue($serviceId, $productId, 'BladeCP Vibe Account ID');
            if ($storedAccount === null || !hash_equals($storedAccount, $accountId)) {
                return null;
            }
            return ['serviceId' => $serviceId, 'productId' => $productId];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function persistBindingVersion(int $serviceId, int $productId, int $bindingVersion): bool
    {
        return $serviceId > 0 && $productId > 0 && $bindingVersion > 0
            && VibeServiceFields::persist([
                'serviceid' => $serviceId,
                'pid' => $productId,
            ], [
                'BladeCP Identity Binding Version' => (string) $bindingVersion,
            ]);
    }

    private static function customFieldValue(int $serviceId, int $productId, string $fieldName): ?string
    {
        $field = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $productId)->where('fieldname', $fieldName)->first();
        $value = $field === null ? null : \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) ($field->id ?? 0))->where('relid', $serviceId)->first();
        $result = trim((string) ($value->value ?? ''));
        return $result === '' ? null : $result;
    }
}
