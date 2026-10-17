<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Native service correlation for a Fast Deploy request.
 *
 * These are product custom-field values, not a module schema.  They are kept
 * admin-only so a client cannot change the relationship that protects the
 * provisioning callbacks.
 */
final class ServiceFields
{
    public const MANAGED = 'BladeCP Fast Deploy Managed';
    public const REQUEST_UUID = 'BladeCP Fast Deploy Request UUID';
    public const INSTALLATION_ID = 'BladeCP Fast Deploy Installation ID';
    public const INVOICE_ID = 'BladeCP Fast Deploy Invoice ID';
    public const PROVISION_UUID = 'BladeCP Provision UUID';

    /** @return array<string,int>|null */
    public static function ensureForProduct(int $productId): ?array
    {
        if ($productId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $schema = Capsule::schema();
            foreach (['tblproducts', 'tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $columns = array_flip($schema->getColumnListing('tblcustomfields'));
            foreach (['type', 'relid', 'fieldname'] as $column) {
                if (!isset($columns[$column])) {
                    return null;
                }
            }
            return Capsule::connection()->transaction(static function () use ($productId, $columns): ?array {
                // The product row is the native, shared lock key. It prevents
                // different clients from racing to add the same product field.
                if (Capsule::table('tblproducts')->where('id', $productId)->lockForUpdate()->first() === null) {
                    return null;
                }
                $result = [];
                foreach ([self::MANAGED, self::REQUEST_UUID, self::INSTALLATION_ID, self::INVOICE_ID, self::PROVISION_UUID] as $name) {
                    $field = Capsule::table('tblcustomfields')
                        ->where('type', 'product')->where('relid', $productId)->where('fieldname', $name)->first();
                    $id = $field === null ? 0 : (int) ($field->id ?? 0);
                    if ($field !== null && isset($columns['adminonly']) && strtolower(trim((string) ($field->adminonly ?? ''))) !== 'on') {
                        return null;
                    }
                    if ($id <= 0) {
                        $values = [
                            'type' => 'product',
                            'relid' => $productId,
                            'fieldname' => $name,
                            'fieldtype' => 'text',
                            'description' => 'BladeCP Fast Deploy service correlation field.',
                            'required' => '',
                            'showorder' => '',
                            'showinvoice' => '',
                            'sortorder' => 0,
                            'adminonly' => 'on',
                        ];
                        $id = (int) Capsule::table('tblcustomfields')->insertGetId(array_intersect_key($values, $columns));
                    }
                    if ($id <= 0) {
                        return null;
                    }
                    $result[$name] = $id;
                }
                return $result;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{serviceId:int,clientId:int,productId:int,serverId:int,status:string,orderId:int,fields:array<string,string>}|null */
    public static function service(int $serviceId, ?int $clientId = null, ?int $serverId = null): ?array
    {
        if ($serviceId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $schema = Capsule::schema();
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
            $row = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($row === null) {
                return null;
            }
            $actualClientId = (int) ($row->userid ?? 0);
            $actualServerId = (int) ($row->server ?? 0);
            $productId = (int) ($row->packageid ?? 0);
            if ($actualClientId <= 0 || $actualServerId <= 0 || $productId <= 0
                || ($clientId !== null && $clientId !== $actualClientId)
                || ($serverId !== null && $serverId !== $actualServerId)) {
                return null;
            }
            $product = Capsule::table('tblproducts')->where('id', $productId)->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                || (string) ($product->configoption1 ?? '') !== 'stack-container') {
                return null;
            }
            $wanted = [self::MANAGED, self::REQUEST_UUID, self::INSTALLATION_ID, self::INVOICE_ID, self::PROVISION_UUID];
            $fieldNames = [];
            foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $productId)->get() as $field) {
                if (in_array((string) ($field->fieldname ?? ''), $wanted, true)) {
                    $fieldNames[(int) ($field->id ?? 0)] = (string) $field->fieldname;
                }
            }
            $fields = [];
            if ($fieldNames !== []) {
                foreach (Capsule::table('tblcustomfieldsvalues')->where('relid', $serviceId)->get() as $value) {
                    $name = $fieldNames[(int) ($value->fieldid ?? 0)] ?? null;
                    if ($name !== null) {
                        $fields[$name] = trim((string) ($value->value ?? ''));
                    }
                }
            }
            return [
                'serviceId' => $serviceId,
                'clientId' => $actualClientId,
                'productId' => $productId,
                'serverId' => $actualServerId,
                'status' => strtolower(trim((string) ($row->domainstatus ?? ''))),
                'orderId' => isset($columns['orderid']) ? (int) ($row->orderid ?? 0) : 0,
                'fields' => $fields,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isManaged(int $serviceId): bool
    {
        return self::read($serviceId)['state'] === 'managed';
    }

    /**
     * Read-only guard for the Stack CreateAccount callback.
     *
     * `unavailable` is intentionally distinct from `absent`: a module
     * callback must never provision when it cannot prove that a marked service
     * is either a normal service or an already-bound migration.
     *
     * @return array{state:'absent'|'managed'|'unavailable',requestUuid?:string,installationId?:string,provisionUuid?:string}
     */
    public static function read(int $serviceId): array
    {
        $service = self::service($serviceId);
        if ($service === null) {
            return ['state' => 'unavailable'];
        }
        if (!hash_equals('1', $service['fields'][self::MANAGED] ?? '')) {
            return ['state' => 'absent'];
        }
        return [
            'state' => 'managed',
            'requestUuid' => $service['fields'][self::REQUEST_UUID] ?? '',
            'installationId' => $service['fields'][self::INSTALLATION_ID] ?? '',
            'provisionUuid' => $service['fields'][self::PROVISION_UUID] ?? '',
        ];
    }

    /** @return array{serviceId:int,clientId:int,productId:int,serverId:int,status:string,orderId:int,fields:array<string,string>}|null */
    public static function findByRequest(string $requestUuid, int $clientId, int $serverId): ?array
    {
        if (!self::validOpaqueId($requestUuid) || $clientId <= 0 || $serverId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $fieldIds = [];
            foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('fieldname', self::REQUEST_UUID)->get() as $field) {
                $fieldIds[] = (int) ($field->id ?? 0);
            }
            foreach (Capsule::table('tblcustomfieldsvalues')->where('value', $requestUuid)->get() as $value) {
                if (!in_array((int) ($value->fieldid ?? 0), $fieldIds, true)) {
                    continue;
                }
                $service = self::service((int) ($value->relid ?? 0), $clientId, $serverId);
                if ($service !== null) {
                    return $service;
                }
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Finds an AddOrder reservation before it has necessarily been assigned a
     * server. `unavailable` is never treated as an absent reservation.
     *
     * @return array{state:'found'|'absent'|'conflict'|'unavailable',serviceId?:int,serverId?:int}
     */
    public static function findReservation(string $requestUuid, string $installationId, int $clientId, int $productId): array
    {
        if (!self::validOpaqueId($requestUuid) || !self::validOpaqueId($installationId) || $clientId <= 0 || $productId <= 0 || !class_exists(Capsule::class)) {
            return ['state' => 'unavailable'];
        }
        try {
            $fieldIds = [];
            foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $productId)->get() as $field) {
                $name = (string) ($field->fieldname ?? '');
                if (in_array($name, [self::MANAGED, self::REQUEST_UUID, self::INSTALLATION_ID], true)) {
                    $fieldIds[$name] = (int) ($field->id ?? 0);
                }
            }
            if ($fieldIds === []) {
                return ['state' => 'absent'];
            }
            if (count($fieldIds) !== 3 || in_array(0, $fieldIds, true)) {
                return ['state' => 'unavailable'];
            }
            foreach (Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldIds[self::REQUEST_UUID])->where('value', $requestUuid)->get() as $requestValue) {
                $serviceId = (int) ($requestValue->relid ?? 0);
                $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
                if ($service === null || (int) ($service->userid ?? 0) !== $clientId || (int) ($service->packageid ?? 0) !== $productId) {
                    continue;
                }
                $values = [];
                foreach (Capsule::table('tblcustomfieldsvalues')->where('relid', $serviceId)->get() as $value) {
                    foreach ($fieldIds as $name => $fieldId) {
                        if ((int) ($value->fieldid ?? 0) === $fieldId) {
                            $values[$name] = trim((string) ($value->value ?? ''));
                        }
                    }
                }
                if (!hash_equals('1', $values[self::MANAGED] ?? '') || !hash_equals($installationId, $values[self::INSTALLATION_ID] ?? '')) {
                    return ['state' => 'conflict'];
                }
                return ['state' => 'found', 'serviceId' => $serviceId, 'serverId' => (int) ($service->server ?? 0)];
            }
            return ['state' => 'absent'];
        } catch (\Throwable) {
            return ['state' => 'unavailable'];
        }
    }

    /** @return array{ok:bool,reason?:string,service?:array<string,mixed>} */
    public static function writeBinding(int $serviceId, int $clientId, int $serverId, string $requestUuid, string $installationId, ?string $provisionUuid = null, ?int $invoiceId = null, bool $authoritativeProvision = false): array
    {
        if (!self::validOpaqueId($requestUuid) || !self::validOpaqueId($installationId)) {
            return ['ok' => false, 'reason' => 'invalid_binding'];
        }
        $service = self::service($serviceId, $clientId, $serverId);
        if ($service === null) {
            return ['ok' => false, 'reason' => 'service_unavailable'];
        }
        $fields = self::ensureForProduct($service['productId']);
        if ($fields === null) {
            return ['ok' => false, 'reason' => 'fields_unavailable'];
        }
        $existing = $service['fields'];
        foreach ([self::REQUEST_UUID => $requestUuid, self::INSTALLATION_ID => $installationId] as $key => $value) {
            if (($existing[$key] ?? '') !== '' && !hash_equals($existing[$key], $value)) {
                return ['ok' => false, 'reason' => 'binding_conflict'];
            }
        }
        if ($provisionUuid !== null && $provisionUuid !== '' && !self::validUuid($provisionUuid)) {
            return ['ok' => false, 'reason' => 'invalid_provision'];
        }
        if ($invoiceId !== null && $invoiceId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_invoice'];
        }
        if (!$authoritativeProvision && $provisionUuid !== null && $provisionUuid !== '' && ($existing[self::PROVISION_UUID] ?? '') !== ''
            && !hash_equals($existing[self::PROVISION_UUID], $provisionUuid)) {
            return ['ok' => false, 'reason' => 'provision_conflict'];
        }
        $values = [self::MANAGED => '1', self::REQUEST_UUID => $requestUuid, self::INSTALLATION_ID => $installationId];
        if ($provisionUuid !== null && $provisionUuid !== '') {
            $values[self::PROVISION_UUID] = $provisionUuid;
        }
        if ($invoiceId !== null) {
            $values[self::INVOICE_ID] = (string) $invoiceId;
        }
        try {
            foreach ($values as $name => $value) {
                $fieldId = $fields[$name];
                $query = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)->where('relid', $serviceId);
                $current = $query->value('value');
                $replaceProvision = $authoritativeProvision && $name === self::PROVISION_UUID;
                if (is_string($current) && $current !== '' && !$replaceProvision && !hash_equals($current, $value)) {
                    return ['ok' => false, 'reason' => 'value_conflict'];
                }
                if ($current === null || $current === false) {
                    Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value]);
                } elseif ($current === '' || $replaceProvision) {
                    $query->update(['value' => $value]);
                }
            }
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'write_failed'];
        }
        $bound = self::service($serviceId, $clientId, $serverId);
        return $bound === null ? ['ok' => false, 'reason' => 'binding_lost'] : ['ok' => true, 'service' => $bound];
    }

    private static function validOpaqueId(string $value): bool
    {
        return $value !== '' && strlen($value) <= 191 && preg_match('/^[A-Za-z0-9._:-]+$/D', $value) === 1;
    }

    private static function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
