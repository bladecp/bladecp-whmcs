<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Commercial Vibe activation for a federation-enabled WHMCS server.
 *
 * This deliberately records the partner's entitlement before an identity is
 * proved in the browser. It never derives a BladeCP identity from an e-mail.
 */
final class VibeDeferredActivation
{
    public const MODE = 'deferred_identity';
    public const ACCESS_ACTIVE = 'active';
    public const ACCESS_SUSPENDED = 'suspended';
    public const ACCESS_TERMINATED = 'terminated';

    /** @return array{installationId:string,serverId:int}|null */
    public static function federation(array $params): ?array
    {
        $serverId = self::positiveId($params['serverid'] ?? $params['server'] ?? null);
        if ($serverId === null || !FederationConfig::enabled($serverId)) {
            return null;
        }
        $config = FederationConfig::forServer($serverId);
        $installationId = trim((string) ($config['installationId'] ?? ''));

        return self::uuid($installationId) ? ['installationId' => $installationId, 'serverId' => $serverId] : null;
    }

    /** @return array{serviceId:int,productId:int,clientId:int,serverId:int,status:string}|null */
    public static function nativeService(array $params): ?array
    {
        $serviceId = self::positiveId($params['serviceid'] ?? null);
        $productId = self::positiveId($params['pid'] ?? null);
        $clientId = self::clientId($params);
        $serverId = self::positiveId($params['serverid'] ?? $params['server'] ?? null);
        if ($serviceId === null || $clientId === null) {
            return null;
        }
        try {
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null) {
                return null;
            }
            $serviceProductId = (int) ($service->packageid ?? 0);
            $serviceServerId = (int) ($service->server ?? 0);
            if (($productId !== null && $productId !== $serviceProductId)
                || ($serverId !== null && $serverId !== $serviceServerId)
                || $serviceProductId <= 0 || $serviceServerId <= 0) { return null; }
            $productId = $serviceProductId;
            $serverId = $serviceServerId;
            $product = Capsule::table('tblproducts')->where('id', $productId)->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $status = strtolower(trim((string) ($service->domainstatus ?? '')));
            return compact('serviceId', 'productId', 'clientId', 'serverId', 'status');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{accountId:string,customerUuid:string,partnerUuid:string,planUuid:string,externalServiceId:string,status:string}|null|false
     * `null` means a confirmed absence. `false` is an unavailable or invalid
     * remote response and must never be treated as an unlinked account.
     */
    public static function externalAccount(Client $client, int $serviceId): array|null|false
    {
        try {
            $response = $client->get('/vibe/accounts/by-external-service/' . rawurlencode((string) $serviceId));
            if (!array_key_exists('found', $response) || !is_bool($response['found'])) {
                return false;
            }
            if ($response['found'] === false) {
                return ($response['account'] ?? null) === null ? null : false;
            }
            if (!is_array($response['account'] ?? null)) {
                return false;
            }
            $account = $response['account'];
            foreach (['accountId', 'customerUuid', 'partnerUuid', 'planUuid', 'externalServiceId', 'status'] as $field) {
                if (trim((string) ($account[$field] ?? '')) === '') {
                    return false;
                }
            }
            if (!hash_equals((string) $serviceId, trim((string) $account['externalServiceId']))) {
                return false;
            }
            return [
                'accountId' => trim((string) $account['accountId']),
                'customerUuid' => trim((string) $account['customerUuid']),
                'partnerUuid' => trim((string) $account['partnerUuid']),
                'planUuid' => trim((string) $account['planUuid']),
                'externalServiceId' => trim((string) $account['externalServiceId']),
                'status' => trim((string) $account['status']),
            ];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function ready(Client $client, string $installationId, string $planUuid): bool
    {
        if (!self::uuid($installationId) || !self::uuid($planUuid)) {
            return false;
        }
        try {
            $response = $client->get('/vibe/commercial-readiness?installationId=' . rawurlencode($installationId)
                . '&planUuid=' . rawurlencode($planUuid));
            return ($response['ready'] ?? false) === true
                && hash_equals($installationId, trim((string) ($response['installationId'] ?? '')))
                && hash_equals($planUuid, trim((string) ($response['planUuid'] ?? '')))
                && trim((string) ($response['planCode'] ?? '')) !== ''
                && ($response['protocol'] ?? '') === 'partner-federation-v2';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isDeferred(array $params): bool
    {
        return hash_equals(self::MODE, strtolower(trim((string) ServiceProperties::get($params, 'BladeCP Vibe Activation Mode', ''))));
    }

    /**
     * Reads the durable service marker while the caller holds the service
     * operation lock. A missing marker is a normal legacy-service condition;
     * an unavailable custom-field store is an error and must fail closed.
     */
    public static function deferredForService(array $params): ?bool
    {
        $value = self::serviceField((int) ($params['serviceid'] ?? 0), 'BladeCP Vibe Activation Mode');
        return $value === null ? null : hash_equals(self::MODE, strtolower($value));
    }

    /** @return self::ACCESS_*|''|null Empty means no durable marker. */
    public static function accessStateForService(int $serviceId): ?string
    {
        $value = self::serviceField($serviceId, 'BladeCP Vibe Access State');
        if ($value === null) { return null; }
        $state = strtolower($value);
        if ($state === '') { return ''; }
        return in_array($state, [self::ACCESS_ACTIVE, self::ACCESS_SUSPENDED, self::ACCESS_TERMINATED], true)
            ? $state
            : null;
    }

    public static function persist(array $params, int $serviceId, string $tier, string $accessState): bool
    {
        $tier = VibePlanTier::normalize($tier) ?? '';
        if ($tier === '' || !in_array($accessState, [self::ACCESS_ACTIVE, self::ACCESS_SUSPENDED, self::ACCESS_TERMINATED], true)) {
            return false;
        }
        $values = [
            'BladeCP Vibe Activation Mode' => self::MODE,
            'BladeCP Vibe Access State' => $accessState,
            'BladeCP External Service ID' => (string) $serviceId,
            'BladeCP Vibe Plan Tier' => $tier,
            'BladeCP Product Type' => 'vibe',
        ];
        if (!VibeServiceFields::persist($params, $values)) {
            return false;
        }
        ServiceProperties::save($params, $values + ['Last Sync' => date('Y-m-d H:i:s')]);
        return true;
    }

    private static function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id !== false && $id > 0 ? (int) $id : null;
    }

    private static function clientId(array $params): ?int
    {
        $details = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];
        return self::positiveId($params['userid'] ?? $params['clientid'] ?? $details['id'] ?? null);
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    private static function serviceField(int $serviceId, string $name): ?string
    {
        if ($serviceId <= 0 || !class_exists(Capsule::class)) { return null; }
        try {
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first(['packageid']);
            $productId = (int) ($service->packageid ?? 0);
            if ($productId <= 0) { return null; }
            $field = Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $productId)
                ->where('fieldname', $name)->first(['id']);
            if ($field === null || (int) ($field->id ?? 0) <= 0) { return ''; }
            $value = Capsule::table('tblcustomfieldsvalues')->where('fieldid', (int) $field->id)
                ->where('relid', $serviceId)->value('value');
            return $value === null ? '' : trim((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
