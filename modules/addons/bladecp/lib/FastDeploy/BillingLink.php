<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Billing link between a migration started outside WHMCS and one native
 * service of this installation.
 *
 * The platform reports which WHMCS service holds the link. Every local
 * identifier the commercial bridge relies on is then read again from that
 * service and its admin-only correlation fields, never from the platform view.
 */
final class BillingLink
{
    public const LINKED = 'linked';

    /** @return array{status:string,serviceId:?int,installationId:?string,clientId:?int} */
    public static function view(array $detail): array
    {
        $link = $detail['billing_link'] ?? $detail['billingLink'] ?? null;
        if (!is_array($link)) {
            return ['status' => 'not_required', 'serviceId' => null, 'installationId' => null, 'clientId' => null];
        }
        $installation = $link['installation_id'] ?? $link['installationId'] ?? null;
        return [
            'status' => strtolower(trim((string) ($link['status'] ?? ''))),
            'serviceId' => self::positive($link['whmcs_service_id'] ?? $link['whmcsServiceId'] ?? null),
            'installationId' => is_string($installation) && trim($installation) !== '' ? strtolower(trim($installation)) : null,
            'clientId' => self::positive($link['whmcs_client_id'] ?? $link['whmcsClientId'] ?? null),
        ];
    }

    public static function isLinked(array $detail): bool
    {
        return self::view($detail)['status'] === self::LINKED;
    }

    /**
     * Rewrites a linked view onto the native correlation that the commercial
     * bridge already validates. Returns null unless the reported service is
     * bound to this request and installation by its admin-only fields.
     *
     * @return array<string,mixed>|null
     */
    public static function project(array $detail, int $serverId, string $installationId, ?int $clientId = null): ?array
    {
        $link = self::view($detail);
        $installationId = strtolower(trim($installationId));
        $requestUuid = trim((string) ($detail['requestUuid'] ?? $detail['request_uuid'] ?? ''));
        if ($link['status'] !== self::LINKED || $link['serviceId'] === null || $serverId <= 0
            || $installationId === '' || $requestUuid === ''
            || ($link['installationId'] !== null && !hash_equals($installationId, $link['installationId']))) {
            return null;
        }
        $service = ServiceFields::service($link['serviceId'], $clientId, $serverId);
        if ($service === null || ($link['clientId'] !== null && $link['clientId'] !== $service['clientId'])
            || !self::bound($service, $requestUuid, $installationId)) {
            return null;
        }
        $commercial = is_array($detail['commercial'] ?? null) ? $detail['commercial'] : [];
        $commercial['externalServiceId'] = $service['serviceId'];
        $detail['commercial'] = $commercial;
        $detail['requestUuid'] = $requestUuid;
        $detail['installationId'] = $installationId;
        $detail['externalClientId'] = (string) $service['clientId'];
        $detail['nativeProductId'] = $service['productId'];
        $detail['billingLink'] = ['status' => self::LINKED, 'whmcsServiceId' => $service['serviceId']];
        unset($detail['billing_link']);
        return $detail;
    }

    /** @param array{fields:array<string,string>} $service */
    public static function bound(array $service, string $requestUuid, string $installationId): bool
    {
        return hash_equals('1', $service['fields'][ServiceFields::MANAGED] ?? '')
            && hash_equals($requestUuid, $service['fields'][ServiceFields::REQUEST_UUID] ?? '')
            && hash_equals($installationId, $service['fields'][ServiceFields::INSTALLATION_ID] ?? '');
    }

    /**
     * Native services of this installation already reserved for a request,
     * in any client account. Cancelled reservations are left out.
     *
     * @return list<array{serviceId:int,clientId:int,productId:int,serverId:int,status:string,orderId:int,fields:array<string,string>}>
     */
    public static function reservations(string $requestUuid, string $installationId): array
    {
        if ($requestUuid === '' || $installationId === '' || !class_exists(Capsule::class)) {
            return [];
        }
        try {
            $fieldIds = [];
            foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', ServiceFields::REQUEST_UUID)->get() as $field) {
                $fieldIds[] = (int) ($field->id ?? 0);
            }
            if ($fieldIds === []) {
                return [];
            }
            $found = [];
            foreach (Capsule::table('tblcustomfieldsvalues')->whereIn('fieldid', $fieldIds)->where('value', $requestUuid)->get() as $value) {
                $service = ServiceFields::service((int) ($value->relid ?? 0));
                if ($service !== null && !in_array($service['status'], ['cancelled', 'terminated', 'fraud'], true)
                    && self::bound($service, $requestUuid, $installationId)) {
                    $found[$service['serviceId']] = $service;
                }
            }
            ksort($found, SORT_NUMERIC);
            return array_values($found);
        } catch (\Throwable) {
            return [];
        }
    }

    private static function positive(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}
