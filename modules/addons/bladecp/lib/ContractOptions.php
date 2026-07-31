<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Seleção de prazo e aceite contratual por plano.
 *
 * O grupo é operacional, separado dos componentes, e permanece vinculado no
 * modo Simples. Termos removidos ficam ocultos para preservar pedidos antigos.
 */
final class ContractOptions
{
    public const GROUP_PREFIX = 'BladeCP Contract: ';
    public const TERM_OPTION = 'BladeCP Contract Term Months';
    public const ACCEPT_OPTION = 'BladeCP Contract Accepted';
    public const TYPE_DROPDOWN = 1;
    public const TYPE_YESNO = 3;
    public const NO_CONTRACT = '0|Sem contrato';
    public const ACCEPTED = '1|Li e aceito os termos contratuais';

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    /**
     * @param array<int,mixed> $contractTerms
     * @return array{group:string,options:array<int,array{name:string,type:int,subs:string[]}>}
     */
    public static function desiredSpec(
        string $planId,
        array $contractTerms,
        bool $requiresContract
    ): array {
        $months = [];
        foreach ($contractTerms as $term) {
            $value = is_array($term)
                ? ($term['termMonths'] ?? $term['months'] ?? null)
                : $term;
            if (!is_numeric($value)) {
                continue;
            }
            $month = (int) $value;
            if ($month <= 0 || isset($months[$month])) {
                continue;
            }
            $months[$month] = $month . '|' . $month . ' meses';
        }
        ksort($months, SORT_NUMERIC);
        $subs = array_values($months);
        if (!$requiresContract && $subs !== []) {
            array_unshift($subs, self::NO_CONTRACT);
        }
        $options = [];
        if ($subs !== []) {
            $options[] = [
                'name' => self::TERM_OPTION,
                'type' => self::TYPE_DROPDOWN,
                'subs' => $subs,
            ];
            $options[] = [
                'name' => self::ACCEPT_OPTION,
                'type' => self::TYPE_YESNO,
                'subs' => [self::ACCEPTED],
            ];
        }

        return [
            'group' => self::GROUP_PREFIX . trim($planId),
            'options' => $options,
        ];
    }

    /**
     * @param array<int,mixed> $contractTerms
     */
    public static function apply(
        string $planId,
        int $productId,
        array $contractTerms,
        bool $requiresContract
    ): bool {
        if ($productId <= 0) {
            return false;
        }
        $spec = self::desiredSpec($planId, $contractTerms, $requiresContract);
        if ($spec['options'] === []) {
            return !$requiresContract;
        }
        try {
            $db = self::db();
            if ($db === null) {
                return false;
            }
            $gid = self::groupId($planId, true);
            if ($gid === null) {
                return false;
            }
            $desiredNames = [];
            foreach ($spec['options'] as $order => $option) {
                $desiredNames[] = $option['name'];
                $row = $db::table('tblproductconfigoptions')
                    ->where('gid', $gid)
                    ->where('optionname', $option['name'])
                    ->first();
                if ($row === null) {
                    $optionId = (int) $db::table('tblproductconfigoptions')
                        ->insertGetId([
                            'gid' => $gid,
                            'optionname' => $option['name'],
                            'optiontype' => $option['type'],
                            'qtyminimum' => 0,
                            'qtymaximum' => 0,
                            'order' => $order + 1,
                            'hidden' => 0,
                        ]);
                } else {
                    $optionId = (int) $row->id;
                    $db::table('tblproductconfigoptions')
                        ->where('id', $optionId)
                        ->update([
                            'optiontype' => $option['type'],
                            'order' => $order + 1,
                            'hidden' => 0,
                        ]);
                }
                self::reconcileSubs($optionId, $option['subs']);
            }
            foreach (
                $db::table('tblproductconfigoptions')->where('gid', $gid)->get() as $row
            ) {
                if (!in_array((string) $row->optionname, $desiredNames, true)) {
                    $db::table('tblproductconfigoptions')
                        ->where('id', (int) $row->id)
                        ->update(['hidden' => 1]);
                }
            }
            ConfigurableOptions::linkProduct($gid, $productId);
            return $db::table('tblproductconfiglinks')
                ->where('gid', $gid)
                ->where('pid', $productId)
                ->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function groupId(string $planId, bool $create): ?int
    {
        $planId = trim($planId);
        if ($planId === '' || !ctype_digit($planId) || (int) $planId <= 0) {
            return null;
        }
        $db = self::db();
        if ($db === null) {
            return null;
        }
        $name = self::GROUP_PREFIX . $planId;
        $row = $db::table('tblproductconfiggroups')->where('name', $name)->first();
        if ($row !== null) {
            return (int) $row->id;
        }
        if (!$create) {
            return null;
        }
        return (int) $db::table('tblproductconfiggroups')->insertGetId([
            'name' => $name,
            'description' => 'Prazo e aceite contratual deste plano BladeCP.',
        ]);
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
                ->get() as $row
        ) {
            $name = (string) $row->optionname;
            $id = (int) $row->id;
            $existingByName[$name] = $id;
            $key = self::identityKey($name);
            if ($key !== null && !isset($existingByKey[$key])) {
                $existingByKey[$key] = $id;
            }
        }
        foreach ($existingByName as $name => $id) {
            if (!in_array($name, $desired, true)) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $id)
                    ->update(['hidden' => 1]);
            }
        }
        foreach (array_values($desired) as $index => $name) {
            $key = self::identityKey($name);
            $id = $key !== null ? ($existingByKey[$key] ?? null) : null;
            if ($id === null) {
                $id = $existingByName[$name] ?? null;
            }
            if ($id !== null) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $id)
                    ->update([
                        'optionname' => $name,
                        'hidden' => 0,
                        'sortorder' => $index + 1,
                    ]);
            } else {
                $id = (int) $db::table('tblproductconfigoptionssub')->insertGetId([
                    'configid' => $optionId,
                    'optionname' => $name,
                    'sortorder' => $index + 1,
                    'hidden' => 0,
                ]);
            }
            ConfigurableOptions::ensurePricing($id);
        }
    }

    /** Prazo é estável pelo prefixo numérico; aceite usa chave fixa. */
    private static function identityKey(string $value): ?string
    {
        if ($value === self::ACCEPTED) {
            return 'accepted';
        }
        $term = trim((string) strtok($value, '|'));
        return ctype_digit($term) ? 'term:' . $term : null;
    }
}
