<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Traduz a escolha de OPÇÕES CONFIGURÁVEIS feita pelo cliente no order
 * form do WHMCS → payload `options:[{option_id, option_value_id,
 * quantity}]` da Customer API (`POST /provisions/simple` no create;
 * convertido p/ `optionUuid`/`optionValueUuid` no `PATCH .../options`).
 *
 * Complementa a seleção de componentes (`ParamsMapper::orderFormSlugs`).
 * O `$valueMap` é fornecido pelo chamador a partir do catálogo ou dos dados
 * persistidos pelo módulo.
 *
 * Contrato de `$configoptions` (WHMCS): keyed pelo `optionname` do
 * config option; o valor é o RÓTULO da sub-option escolhida (dropdown/
 * radio/yesno) ou um NÚMERO (quantity). Mesmo formato que o módulo já
 * consome para componentes.
 *
 * Regras de persistência:
 *  - SÓ seleções NÃO-default entram no payload. O valor default
 *    ("Nenhum (incluído)") = mantém o base do produto ⇒ sem linha.
 *  - `resourceKey` NÃO vai no payload (o backend resolve o sizing no
 *    `enrichProvision` a partir do `optionValueId`).
 *  - `option_id`/`option_value_id` carregam os UUIDs do catálogo
 *    (o módulo só conhece UUIDs — o `/provisions/simple` resolve, como
 *    já faz com `slug` de componente).
 */
final class OptionsMapper
{
    /**
     * Catálogo parseado (`OptionCatalog::parseGroups`) → mapa de
     * resolução `optionName → {type, values: {valueLabel → {...}}}`.
     * Pura.
     *
     * @param array<int,array<string,mixed>> $groups
     * @return array<string,array{type:int,values:array<string,array{option_id:string,option_value_id:string,is_default:bool,resource_key:?string}>}>
     */
    public static function valueMapFromGroups(array $groups): array
    {
        $map = [];
        foreach ($groups as $g) {
            $options = is_array($g['options'] ?? null) ? $g['options'] : [];
            foreach ($options as $o) {
                $optName = trim((string) ($o['name'] ?? ''));
                $optUuid = trim((string) ($o['uuid'] ?? ''));
                if ($optName === '' || $optUuid === '') {
                    continue;
                }
                $type = (int) ($o['optionType'] ?? 1);
                $resourceKey = isset($o['resourceKey'])
                    && is_string($o['resourceKey'])
                    ? $o['resourceKey'] : null;
                if (!isset($map[$optName])) {
                    $map[$optName] = ['type' => $type, 'values' => []];
                }
                $values = is_array($o['values'] ?? null) ? $o['values'] : [];
                foreach ($values as $v) {
                    $label = trim((string) ($v['label'] ?? ''));
                    $valUuid = trim((string) ($v['uuid'] ?? ''));
                    if ($label === '' || $valUuid === '') {
                        continue;
                    }
                    $map[$optName]['values'][$label] = [
                        'option_id' => $optUuid,
                        'option_value_id' => $valUuid,
                        'is_default' => !empty($v['isDefault']),
                        'resource_key' => $resourceKey,
                    ];
                }
            }
        }
        return $map;
    }

    /**
     * `$configoptions` do WHMCS + `$valueMap` → payload de opções.
     * SÓ seleções não-default; dedupe por `option_id`. Pura.
     *
     * @param array<string,mixed> $configoptions
     * @param array<string,array{type:int,values:array<string,array<string,mixed>>}> $valueMap
     * @return array<int,array{option_id:string,option_value_id:string,quantity:int}>
     */
    public static function toOptions(array $configoptions, array $valueMap): array
    {
        $out = [];
        $seen = [];
        foreach ($configoptions as $name => $val) {
            $name = (string) $name;
            if (!isset($valueMap[$name])) {
                continue; // não é uma opção configurável nossa
            }
            $type = (int) ($valueMap[$name]['type'] ?? 1);
            $values = is_array($valueMap[$name]['values'] ?? null)
                ? $valueMap[$name]['values'] : [];
            if ($values === []) {
                continue;
            }

            if ($type === 4) {
                // quantity (optiontype=4): o valor é o NÚMERO digitado;
                // a option tem um único value (a unidade) — qty>0 cobra.
                $qty = is_numeric($val) ? (int) $val : 0;
                if ($qty <= 0) {
                    continue;
                }
                $picked = self::pickUnitValue($values);
                if ($picked === null) {
                    continue;
                }
                [, $entry] = $picked;
                self::push($out, $seen, $entry, $qty);
                continue;
            }

            // dropdown/radio/yesno: o valor é o RÓTULO da sub-option.
            $label = is_string($val) ? trim($val) : (string) $val;
            if ($label === '' || $label === '0') {
                continue;
            }
            if (!isset($values[$label])) {
                continue; // rótulo desconhecido → não inventa seleção
            }
            $entry = $values[$label];
            if (!empty($entry['is_default'])) {
                continue; // default = base, sem linha
            }
            self::push($out, $seen, $entry, 1);
        }
        return $out;
    }

    /**
     * Primeira value NÃO-default (a "unidade" de uma quantity); cai na
     * 1ª value se todas forem default. `[label, entry]` ou null.
     *
     * @param array<string,array<string,mixed>> $values
     * @return array{0:string,1:array<string,mixed>}|null
     */
    private static function pickUnitValue(array $values): ?array
    {
        $first = null;
        foreach ($values as $label => $entry) {
            if ($first === null) {
                $first = [$label, $entry];
            }
            if (empty($entry['is_default'])) {
                return [$label, $entry];
            }
        }
        return $first;
    }

    /**
     * @param array<int,array{option_id:string,option_value_id:string,quantity:int}> $out
     * @param array<string,bool> $seen
     * @param array<string,mixed> $entry
     */
    private static function push(array &$out, array &$seen, array $entry, int $qty): void
    {
        $oid = trim((string) ($entry['option_id'] ?? ''));
        $vid = trim((string) ($entry['option_value_id'] ?? ''));
        if ($oid === '' || $vid === '' || isset($seen[$oid])) {
            return;
        }
        $seen[$oid] = true;
        $out[] = [
            'option_id' => $oid,
            'option_value_id' => $vid,
            'quantity' => $qty,
        ];
    }
}
