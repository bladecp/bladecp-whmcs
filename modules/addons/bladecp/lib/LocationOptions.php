<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Location obrigatória e isolada por plano.
 *
 * O grupo de composição pode ser removido no modo Simples. Este grupo
 * operacional permanece vinculado e grava o id numérico como valor da
 * subopção (`id|label`), sem reconstruir identidade pelo texto exibido.
 */
final class LocationOptions
{
    public const GROUP_PREFIX = 'BladeCP Location: ';
    public const OPTION_NAME = 'Location';
    public const TYPE_RADIO = 2;

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    /** @param array<string,string> $locations */
    public static function desiredSpec(string $planId, array $locations): array
    {
        $subs = [];
        foreach ($locations as $id => $label) {
            $id = trim((string) $id);
            $label = trim((string) $label);
            if ($id === '' || !ctype_digit($id) || (int) $id <= 0 || $label === '') {
                continue;
            }
            $subs[] = $id . '|' . $label;
        }

        return [
            'group' => self::GROUP_PREFIX . trim($planId),
            'option' => self::OPTION_NAME,
            'subs' => array_values(array_unique($subs)),
        ];
    }

    public static function groupId(string $planId, bool $create = false): ?int
    {
        $planId = trim($planId);
        if ($planId === '' || !ctype_digit($planId) || (int) $planId <= 0) {
            return null;
        }
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $name = self::GROUP_PREFIX . $planId;
            $group = $db::table('tblproductconfiggroups')
                ->where('name', $name)
                ->first();
            if ($group !== null) {
                return (int) $group->id;
            }
            if (!$create) {
                return null;
            }

            return (int) $db::table('tblproductconfiggroups')->insertGetId([
                'name' => $name,
                'description' => 'Locations disponíveis para este plano BladeCP.',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reconcilia e vincula o grupo ao produto.
     *
     * Catálogo vazio é fail-closed: não cria grupo novo e não remove opções
     * existentes, evitando quebrar pedidos quando a API está indisponível.
     *
     * @param array<string,string> $locations
     */
    public static function apply(string $planId, int $productId, array $locations): bool
    {
        if ($productId <= 0 || $locations === []) {
            return false;
        }
        try {
            $db = self::db();
            $gid = self::groupId($planId, true);
            if ($db === null || $gid === null) {
                return false;
            }
            $spec = self::desiredSpec($planId, $locations);
            if ($spec['subs'] === []) {
                return false;
            }
            $option = $db::table('tblproductconfigoptions')
                ->where('gid', $gid)
                ->where('optionname', self::OPTION_NAME)
                ->first();
            if ($option === null) {
                $optionId = (int) $db::table('tblproductconfigoptions')
                    ->insertGetId([
                        'gid' => $gid,
                        'optionname' => self::OPTION_NAME,
                        'optiontype' => self::TYPE_RADIO,
                        'qtyminimum' => 0,
                        'qtymaximum' => 0,
                        'order' => 1,
                        'hidden' => 0,
                    ]);
            } else {
                $optionId = (int) $option->id;
                $db::table('tblproductconfigoptions')
                    ->where('id', $optionId)
                    ->update(['optiontype' => self::TYPE_RADIO, 'hidden' => 0]);
            }

            self::reconcileSubs($optionId, $spec['subs']);
            ConfigurableOptions::linkProduct($gid, $productId);
            return $db::table('tblproductconfiglinks')
                ->where('gid', $gid)
                ->where('pid', $productId)
                ->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param string[] $desired */
    private static function reconcileSubs(int $optionId, array $desired): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $existingByName = [];
        $existingByKey = [];
        foreach (
            $db::table('tblproductconfigoptionssub')
                ->where('configid', $optionId)
                ->get() as $sub
        ) {
            $name = (string) $sub->optionname;
            $id = (int) $sub->id;
            $existingByName[$name] = $id;
            $key = self::identityKey($name);
            if ($key !== null && !isset($existingByKey[$key])) {
                $existingByKey[$key] = $id;
            }
        }

        // Não apagamos valores históricos. Opções removidas do catálogo ficam
        // ocultas, enquanto seleções já persistidas continuam resolvíveis.
        foreach ($existingByName as $name => $id) {
            if (!in_array($name, $desired, true)) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $id)
                    ->update(['hidden' => 1]);
            }
        }
        $order = 0;
        foreach ($desired as $name) {
            $order++;
            $id = self::identityKey($name);
            $subId = $id !== null ? ($existingByKey[$id] ?? null) : null;
            if ($subId === null) {
                $subId = $existingByName[$name] ?? null;
            }
            if ($subId !== null) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $subId)
                    ->update([
                        'optionname' => $name,
                        'hidden' => 0,
                        'sortorder' => $order,
                    ]);
                ConfigurableOptions::ensurePricing($subId);
                continue;
            }
            $id = (int) $db::table('tblproductconfigoptionssub')->insertGetId([
                'configid' => $optionId,
                'optionname' => $name,
                'sortorder' => $order,
                'hidden' => 0,
            ]);
            ConfigurableOptions::ensurePricing($id);
        }
    }

    /** Identidade da location é o id numérico antes do rótulo exibido. */
    private static function identityKey(string $value): ?string
    {
        $id = trim((string) strtok($value, '|'));
        return ctype_digit($id) && (int) $id > 0 ? $id : null;
    }
}
