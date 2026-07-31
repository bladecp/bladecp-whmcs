<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Config;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Carrega opções de plano e localização para os campos do produto. */
final class Loaders
{
    private const CACHE_TTL = 3600;
    // Arquivos de cache separados para planos e localizações.
    private const CACHE_PLANS = 'bladecp_partner_plans_v3.json';
    private const CACHE_LOCATIONS = 'bladecp_partner_locations_v3.json';

    /** @return array<string,string> */
    public static function plans(?Client $client = null): array
    {
        $client ??= ServerConfig::client();
        if ($client === null) {
            return [];
        }
        $cached = self::readCache($client, self::CACHE_PLANS);
        if ($cached !== null) {
            return $cached;
        }
        try {
            $map = self::parsePlans($client->get('/partner/plans'));
        } catch (\Throwable) {
            return [];
        }
        self::writeCache($client, self::CACHE_PLANS, $map);
        return $map;
    }

    /** @return array<string,string> */
    public static function locations(
        ?Client $client = null,
        int|string|null $planId = null
    ): array
    {
        $planId = is_numeric($planId) && (int) $planId > 0
            ? (string) (int) $planId
            : null;
        $client ??= ServerConfig::client();
        if ($client === null) {
            return [];
        }
        $cacheFile = self::locationsCacheFile($planId);
        $cached = self::readCache($client, $cacheFile);
        if ($cached !== null) {
            return $cached;
        }
        try {
            $path = '/partner/locations';
            if ($planId !== null) {
                $path .= '?plan_id=' . rawurlencode($planId);
            }
            $rows = $client->get($path);
        } catch (\Throwable) {
            return [];
        }
        $map = self::parseLocations($rows);
        self::writeCache($client, $cacheFile, $map);
        return $map;
    }

    private static function locationsCacheFile(?string $planId): string
    {
        return $planId === null
            ? self::CACHE_LOCATIONS
            : 'bladecp_partner_locations_plan_' . $planId . '_v3.json';
    }

    /** @return array<string,string> */
    public static function parsePlans(mixed $response): array
    {
        $prodPrio = static function (string $p): int {
            return match (strtolower(trim($p))) {
                'stack container' => 0,
                'edge cloud' => 1,
                'hyper cloud' => 2,
                'quantum steel' => 3,
                default => 4,
            };
        };
        $list = [];
        foreach (self::rows($response) as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = "Plano #{$id}";
            }
            $product = trim((string) ($row['productName'] ?? ''));
            $type = strtolower(trim((string) ($row['planType'] ?? '')));

            if ($product === '') {
                $label = $name;
            } elseif (
                strtolower($product) === 'stack container'
                && ($type === 'hosting' || $type === 'reseller')
            ) {
                $label = "{$name} ({$product}/" . ucfirst($type) . ')';
            } else {
                $label = "{$name} ({$product})";
            }

            $typePrio = $type === 'hosting'
                ? 0
                : ($type === 'reseller' ? 1 : 2);
            $list[] = [
                'id' => $id,
                'idNum' => is_numeric($id) ? (int) $id : PHP_INT_MAX,
                'pp' => $prodPrio($product),
                'tp' => $typePrio,
                'label' => $label,
            ];
        }
        usort($list, static fn (array $a, array $b): int =>
            $a['pp'] <=> $b['pp']
            ?: $a['tp'] <=> $b['tp']
            ?: $a['idNum'] <=> $b['idNum']);
        $out = [];
        foreach ($list as $p) {
            $out[$p['id']] = $p['label'];
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function parseLocations(mixed $response): array
    {
        $out = [];
        foreach (self::rows($response) as $row) {
            if (!isset($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $region = trim((string) ($row['region'] ?? ''));
            $city = trim((string) ($row['city'] ?? ''));
            $cc = trim((string) ($row['countryCode'] ?? ''));
            $label = $region !== '' ? $region : "Location #{$id}";
            if ($city !== '') {
                $label .= " · {$city}";
            }
            if ($cc !== '') {
                $label .= " ({$cc})";
            }
            $out[$id] = $label;
        }
        return $out;
    }

    /**
     * Normaliza pra lista de rows: aceita `{data:[...]}` ou lista crua.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rows(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response)) {
            return [];
        }
        return array_values(array_filter(
            $response,
            static fn ($r) => is_array($r)
        ));
    }

    /** @return array<string,string>|null */
    private static function readCache(Client $client, string $file): ?array
    {
        $decoded = CacheStore::read($client, $file, self::CACHE_TTL);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($v)) {
                $out[(string) $k] = $v;
            }
        }
        return $out;
    }

    /** @param array<string,string> $map */
    private static function writeCache(Client $client, string $file, array $map): void
    {
        CacheStore::write($client, $file, $map);
    }
}
