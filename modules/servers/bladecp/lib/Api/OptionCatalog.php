<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Catálogo de opções configuráveis, com consulta global ou filtrada por plano.
 *
 * Os resultados são normalizados e armazenados temporariamente para reduzir
 * consultas repetidas. Falhas de leitura retornam `null` para que a interface
 * possa informar indisponibilidade sem interromper o fluxo.
 */
final class OptionCatalog
{
    private const CACHE_TTL = 3600;
    // Arquivo de cache da consulta sem filtro de plano.
    private const CACHE_FILE = 'bladecp_option_groups_v2.json';
    // Prefixo dos arquivos de cache filtrados por plano.
    private const CACHE_PLAN_PREFIX = 'bladecp_option_groups_plan_';

    /** @return array<int,array<string,mixed>>|null */
    public static function groupsFor(Client $client, ?string $currency = null): ?array
    {
        return self::fetch($client, null, $currency);
    }

    /** @param int|string $plan @return array<int,array<string,mixed>>|null */
    public static function groupsForPlan(
        Client $client,
        int|string $plan,
        ?string $currency = null
    ): ?array {
        $plan = trim((string) $plan);
        if ($plan === '') {
            // Sem plano, consulta o catálogo disponível para a conta.
            return self::fetch($client, null, $currency);
        }
        return self::fetch($client, $plan, $currency);
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function fetch(
        Client $client,
        ?string $plan,
        ?string $currency
    ): ?array {
        $hasCurrency = $currency !== null && trim($currency) !== '';
        $cacheFile = self::cacheFileFor($plan);
        if (!$hasCurrency) {
            $cached = self::readCache($client, $cacheFile);
            if ($cached !== null) {
                return $cached;
            }
        }
        try {
            $query = [];
            if ($plan !== null && $plan !== '') {
                $query[] = 'plan=' . rawurlencode($plan);
            }
            if ($hasCurrency) {
                $query[] = 'currency='
                    . rawurlencode(strtoupper(trim((string) $currency)));
            }
            $path = '/partner/option-groups'
                . ($query !== [] ? '?' . implode('&', $query) : '');
            $groups = self::parseGroups($client->get($path));
        } catch (\Throwable) {
            return null;
        }
        if (!$hasCurrency) {
            self::writeCache($client, $cacheFile, $groups);
        }
        return $groups;
    }

    /**
     * @return array<int,array{
     *   uuid:string,name:string,slug:string,description:string,
     *   displayOrder:int,
     *   options:array<int,array{
     *     uuid:string,name:string,slug:string,inputType:string,
     *     optionType:int,resourceKey:?string,unit:?string,
     *     required:bool,allowDowngrade:bool,minQty:?int,maxQty:?int,
     *     displayOrder:int,
     *     values:array<int,array{
     *       uuid:string,label:string,value:string,isDefault:bool,
     *       displayOrder:int,resourceValue:?int,
     *       currencies:array<int,array<string,mixed>>
     *     }>
     *   }>
     * }>
     */
    public static function parseGroups(mixed $response): array
    {
        if (
            is_array($response)
            && isset($response['data'])
            && is_array($response['data'])
        ) {
            $response = $response['data'];
        }
        if (!is_array($response)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($response as $g) {
            if (!is_array($g)) {
                continue;
            }
            $uuid = trim((string) ($g['uuid'] ?? ''));
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            $name = trim((string) ($g['name'] ?? ''));
            $out[] = [
                'uuid' => $uuid,
                'name' => $name !== '' ? $name : $uuid,
                'slug' => trim((string) ($g['slug'] ?? '')),
                'description' => trim((string) ($g['description'] ?? '')),
                'displayOrder' => (int) ($g['displayOrder'] ?? 0),
                'options' => self::parseOptions($g['options'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * Converte o tipo de entrada recebido no tipo de opção do WHMCS.
     *  - dropdown → 1 (dropdown)
     *  - radio    → 2 (radio)
     *  - slider   → 2 (radio; slider é só dica de widget, snapa em
     *               valores discretos — dado idêntico a radio)
     *  - yesno    → 3 (yes/no)
     *  - quantity → 4 (quantity)
     *  - desconhecido → 1 (dropdown, fallback seguro)
     */
    public static function inputTypeToOptionType(string $inputType): int
    {
        return match (strtolower(trim($inputType))) {
            'radio', 'slider' => 2,
            'yesno' => 3,
            'quantity' => 4,
            default => 1,
        };
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function parseOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $o) {
            if (!is_array($o)) {
                continue;
            }
            $uuid = trim((string) ($o['uuid'] ?? ''));
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            $name = trim((string) ($o['name'] ?? ''));
            $inputType = trim((string) ($o['inputType'] ?? 'dropdown'));
            $resourceKey = isset($o['resourceKey'])
                && is_string($o['resourceKey'])
                && trim($o['resourceKey']) !== ''
                ? trim($o['resourceKey'])
                : null;
            $unit = isset($o['unit']) && is_string($o['unit'])
                && trim($o['unit']) !== ''
                ? trim($o['unit'])
                : null;
            $out[] = [
                'uuid' => $uuid,
                'name' => $name !== '' ? $name : $uuid,
                'slug' => trim((string) ($o['slug'] ?? '')),
                'inputType' => $inputType,
                'optionType' => self::inputTypeToOptionType($inputType),
                'resourceKey' => $resourceKey,
                'unit' => $unit,
                'required' => !empty($o['required']),
                'allowDowngrade' => !empty($o['allowDowngrade']),
                'minQty' => isset($o['minQty']) && is_numeric($o['minQty'])
                    ? (int) $o['minQty'] : null,
                'maxQty' => isset($o['maxQty']) && is_numeric($o['maxQty'])
                    ? (int) $o['maxQty'] : null,
                'displayOrder' => (int) ($o['displayOrder'] ?? 0),
                'values' => self::parseValues($o['values'] ?? null, $resourceKey),
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function parseValues(mixed $raw, ?string $resourceKey): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $v) {
            if (!is_array($v)) {
                continue;
            }
            $uuid = trim((string) ($v['uuid'] ?? ''));
            if ($uuid === '' || isset($seen[$uuid])) {
                continue;
            }
            $seen[$uuid] = true;
            $value = (string) ($v['value'] ?? '');
            $label = trim((string) ($v['label'] ?? ''));
            // Valor numérico usado para exibição e comparação local.
            $resourceValue = $resourceKey !== null && is_numeric($value)
                ? (int) $value
                : null;
            $out[] = [
                'uuid' => $uuid,
                'label' => $label !== '' ? $label : $value,
                'value' => $value,
                'isDefault' => !empty($v['isDefault']),
                'displayOrder' => (int) ($v['displayOrder'] ?? 0),
                'resourceValue' => $resourceValue,
                'currencies' => self::parseCurrencies($v['currencies'] ?? null),
            ];
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function parseCurrencies(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $fields = [
            'priceHourly', 'priceMonthly', 'priceQuarterly',
            'priceHalfYearly', 'priceYearly', 'priceTwoYears',
            'priceThreeYears', 'setupFee',
        ];
        $out = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $code = strtoupper(trim((string) ($c['currency'] ?? '')));
            if ($code === '') {
                continue;
            }
            $row = ['currency' => $code];
            foreach ($fields as $f) {
                $row[$f] = isset($c[$f]) && is_numeric($c[$f])
                    ? (float) $c[$f]
                    : null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function readCache(Client $client, string $file): ?array
    {
        $decoded = CacheStore::read($client, $file, self::CACHE_TTL);
        if (!is_array($decoded)) {
            return null;
        }
        // Normaliza o conteúdo lido do cache antes de usá-lo.
        return self::parseGroups($decoded);
    }

    /** @param array<int,array<string,mixed>> $groups */
    private static function writeCache(Client $client, string $file, array $groups): void
    {
        CacheStore::write($client, $file, array_values($groups));
    }

    /** Retorna o nome seguro do arquivo de cache para a consulta. */
    private static function cacheFileFor(?string $plan): string
    {
        if ($plan === null || $plan === '') {
            return self::CACHE_FILE;
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $plan) ?? '';
        return self::CACHE_PLAN_PREFIX . $safe . '_v2.json';
    }

}
