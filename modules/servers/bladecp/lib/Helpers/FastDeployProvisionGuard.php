<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Prevents a native setup callback from creating a second migration destination. */
final class FastDeployProvisionGuard
{
    public static function check(array $params): ?string
    {
        $managed = ServiceProperties::get($params, 'BladeCP Fast Deploy Managed', '');
        $request = ServiceProperties::get($params, 'BladeCP Fast Deploy Request UUID', '');
        $provision = ServiceProperties::provisionUuid($params);
        if (class_exists(\WHMCS\Database\Capsule::class)) {
            try {
                $serviceId = (int) ($params['serviceid'] ?? 0);
                $productId = (int) ($params['pid'] ?? 0);
                if ($serviceId <= 0 || $productId <= 0) {
                    return self::blocked();
                }
                $fields = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues as v')
                    ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                    ->where('v.relid', $serviceId)->where('f.relid', $productId)
                    ->where('f.type', 'product')
                    ->whereIn('f.fieldname', ['BladeCP Fast Deploy Managed', 'BladeCP Fast Deploy Request UUID', 'BladeCP Provision UUID'])
                    ->select(['f.fieldname', 'f.adminonly', 'v.value'])->get();
                foreach ($fields as $field) {
                    if ((string) $field->value === '') { continue; }
                    if ($field->fieldname === 'BladeCP Fast Deploy Managed') {
                        if (!in_array((string) $field->adminonly, ['on', '1'], true)) { return self::blocked(); }
                        $managed = (string) $field->value;
                    } elseif ($field->fieldname === 'BladeCP Fast Deploy Request UUID') {
                        $request = (string) $field->value;
                    } else {
                        $provision = (string) $field->value;
                    }
                }
            } catch (\Throwable) {
                return self::blocked();
            }
        }
        if ($managed === '' && $request === '') { return null; }
        if ((string) $managed !== '1' || !ParamsMapper::isCanonicalUuid($request)
            || !ParamsMapper::isCanonicalUuid($provision)) {
            return self::blocked();
        }
        return 'success';
    }

    private static function blocked(): string
    {
        return 'BladeCP: este serviço aguarda a confirmação do destino de migração. Nenhuma nova hospedagem foi criada.';
    }
}
