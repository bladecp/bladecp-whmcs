<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Persists Vibe webhook correlation fields through WHMCS Capsule only. */
final class VibeServiceFields
{
    /** @param array<string,mixed> $params @param array<string,string> $values */
    public static function persist(array $params, array $values): bool
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $productId = (int) ($params['pid'] ?? 0);
        if ($serviceId <= 0 || $productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblcustomfields') || !$schema->hasTable('tblcustomfieldsvalues')) {
                return false;
            }
            $columns = array_flip($schema->getColumnListing('tblcustomfields'));
            foreach ($values as $name => $value) {
                $name = trim($name);
                $value = trim($value);
                if ($name === '' || $value === '') {
                    return false;
                }
                $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                    ->where('type', 'product')
                    ->where('relid', $productId)
                    ->where('fieldname', $name)
                    ->first(['id']);
                $fieldId = $field === null ? 0 : (int) ($field->id ?? 0);
                if ($fieldId <= 0) {
                    $fieldId = (int) \WHMCS\Database\Capsule::table('tblcustomfields')->insertGetId(
                        array_intersect_key([
                            'type' => 'product',
                            'relid' => $productId,
                            'fieldname' => $name,
                            'fieldtype' => 'text',
                            'description' => 'BladeCP Vibe service correlation field.',
                            'required' => '',
                            'showorder' => '',
                            'showinvoice' => '',
                            'sortorder' => 0,
                            'adminonly' => 'on',
                        ], $columns)
                    );
                }
                if ($fieldId <= 0) {
                    return false;
                }
                $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', $fieldId)->where('relid', $serviceId)->first(['id']);
                if ($existing === null) {
                    \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')->insert([
                        'fieldid' => $fieldId,
                        'relid' => $serviceId,
                        'value' => $value,
                    ]);
                } else {
                    \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('id', (int) $existing->id)->update(['value' => $value]);
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
