<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Produces the authoritative commercial snapshot from WHMCS itself. Product
 * ids are sent only over the authenticated Customer API channel and never
 * projected to Vibe/browser clients.
 */
final class VibeCommercialCatalog
{
    /** @return array{revision:string,entries:list<array<string,mixed>>} */
    public static function snapshotForServer(int $serverId): array
    {
        $collected = self::collectForServer($serverId);
        $entries = $collected['entries'];
        $canonical = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return [
            'revision' => hash('sha256', $canonical),
            'entries' => $entries,
        ];
    }

    /** @return array{ok:bool,reason:string,revision:string,count:int} */
    public static function syncForServer(int $serverId): array
    {
        $result = self::performSync($serverId);
        try {
            $client = ServerConfig::clientForServerId($serverId);
            if ($client !== null) {
                CacheStore::write($client, 'vibe-catalog-attempt-' . $serverId, $result + ['attemptedAt' => gmdate('c')]);
            }
        } catch (\Throwable) {
            // Diagnostics must not change the outcome of a product operation.
        }
        return $result;
    }

    public static function lastAttemptForServer(int $serverId): ?array
    {
        try {
            $client = ServerConfig::clientForServerId($serverId);
            return $client === null ? null : CacheStore::read($client, 'vibe-catalog-attempt-' . $serverId, 30 * 86400);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function performSync(int $serverId): array
    {
        if ($serverId <= 0) {
            return self::result(false, 'invalid-server');
        }
        try {
            $client = ServerConfig::clientForServerId($serverId);
            if ($client === null) {
                return self::result(false, 'server-unavailable');
            }
            $collected = self::collectForServer($serverId);
            if (!$collected['complete']) {
                return self::result(false, 'snapshot-incomplete');
            }
            $entries = $collected['entries'];
            $snapshot = [
                'revision' => hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'entries' => $entries,
            ];
            $client->syncVibeCommercialCatalog([
                'catalogRevision' => $snapshot['revision'],
                'entries' => $snapshot['entries'],
            ]);
            return [
                'ok' => true,
                'reason' => 'synced',
                'revision' => $snapshot['revision'],
                'count' => count($snapshot['entries']),
            ];
        } catch (ApiException $error) {
            $code = $error->body['code'] ?? $error->body['error']['code'] ?? '';
            return self::result(false, $code === 'commercial_catalog_mapping_invalid' ? 'mapping-conflict' : 'sync-failed');
        } catch (\Throwable) {
            return self::result(false, 'sync-failed');
        }
    }

    /**
     * The API has one commercial price snapshot per offer. Choose exactly one
     * deterministic price: account default currency when available, otherwise
     * the first alphabetic currency, then the shortest enabled cycle. Hidden
     * products become explicit tombstones rather than silently surviving.
     *
     * @return list<array<string,mixed>>
     */
    public static function entriesForServer(int $serverId): array
    {
        return self::collectForServer($serverId)['entries'];
    }

    /** @return array{complete:bool,entries:list<array<string,mixed>>} */
    private static function collectForServer(int $serverId): array
    {
        if ($serverId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return ['complete' => false, 'entries' => []];
        }
        try {
            $groupId = Provisioning::serverGroup($serverId);
            if ($groupId <= 0) {
                return ['complete' => false, 'entries' => []];
            }
            $plans = self::planUuidsForServer($serverId);
            if ($plans === null) {
                return ['complete' => false, 'entries' => []];
            }
            $currencies = Pricing::currencies();
            $rows = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('configoption1', 'vibe')
                ->where('servergroup', $groupId)
                ->orderBy('id')
                ->get(['id', 'name', 'hidden', 'configoption2', 'configoption4']);
            $byPlan = [];
            foreach ($rows as $row) {
                $productId = (int) ($row->id ?? 0);
                $reference = trim((string) ($row->configoption2 ?? ''));
                $planUuid = $plans[$reference] ?? (self::isUuid($reference) ? $reference : null);
                if ($productId <= 0 || $planUuid === null) {
                    continue;
                }
                // The API uses this explicit tier to gate the entitlement.
                // Never derive it from pricing, billing cycle, or paytype.
                $planCode = VibePlanTier::normalize($row->configoption4 ?? null);
                if ($planCode === null) {
                    return ['complete' => false, 'entries' => []];
                }
                if (isset($byPlan[$planUuid]) && ($byPlan[$planUuid]['planCode'] ?? null) !== $planCode) {
                    return ['complete' => false, 'entries' => []];
                }
                $visible = empty($row->hidden);
                $entry = self::entry(
                    $productId,
                    $planUuid,
                    $planCode,
                    $visible,
                    $visible ? self::primaryPrice($productId, $currencies) : null
                );
                if (!$entry['available']) {
                    $byPlan[$planUuid] ??= $entry;
                    continue;
                }
                if (!isset($byPlan[$planUuid]) || empty($byPlan[$planUuid]['available'])) {
                    $byPlan[$planUuid] = $entry;
                }
            }
            ksort($byPlan, SORT_STRING);
            return ['complete' => true, 'entries' => array_values($byPlan)];
        } catch (\Throwable) {
            return ['complete' => false, 'entries' => []];
        }
    }

    /**
     * Every catalog entry carries the canonical price field. A product is
     * sellable only when it is visible and has one deterministic price.
     *
     * @param array{amountMinor:int,currency:string,billingCycle:string,display:string}|null $price
     * @return array{whmcsProductId:int,planUuid:string,planCode:string,available:bool,price:array{amountMinor:int,currency:string,billingCycle:string,display:string}|null}
     */
    private static function entry(int $productId, string $planUuid, string $planCode, bool $visible, ?array $price): array
    {
        $available = $visible && $price !== null;
        return [
            'whmcsProductId' => $productId,
            'planUuid' => $planUuid,
            'planCode' => $planCode,
            'available' => $available,
            'price' => $available ? $price : null,
        ];
    }

    /** @param array<int,string> $currencies @return array{amountMinor:int,currency:string,billingCycle:string,display:string}|null */
    private static function primaryPrice(int $productId, array $currencies): ?array
    {
        ksort($currencies, SORT_NUMERIC);
        $candidates = [];
        foreach ($currencies as $currencyId => $currency) {
            $cycles = Pricing::currentCycles($productId, (int) $currencyId);
            foreach ($cycles as $cycle => $amount) {
                if ($amount === null || !in_array($cycle, ['monthly', 'quarterly', 'semiannually', 'annually'], true)) {
                    continue;
                }
                $minor = (int) round($amount * 100);
                $candidates[] = [
                    'amountMinor' => $minor,
                    'currency' => $currency,
                    'billingCycle' => $cycle,
                    'display' => self::display($minor, $currency, $cycle),
                ];
            }
        }
        usort($candidates, static function (array $left, array $right): int {
            $currency = strcmp((string) $left['currency'], (string) $right['currency']);
            if ($currency !== 0) {
                return $currency;
            }
            $order = ['monthly' => 0, 'quarterly' => 1, 'semiannually' => 2, 'annually' => 3];
            return ($order[$left['billingCycle']] ?? 9) <=> ($order[$right['billingCycle']] ?? 9);
        });
        foreach ($candidates as $candidate) {
            if ($candidate['currency'] === 'BRL') {
                return $candidate;
            }
        }
        return $candidates[0] ?? null;
    }

    /** @return array{amountMinor:int,currency:string,billingCycle:string,display:string}|null */
    public static function primaryPriceForProduct(int $productId): ?array
    {
        return $productId > 0 ? self::primaryPrice($productId, Pricing::currencies()) : null;
    }

    private static function display(int $amountMinor, string $currency, string $cycle): string
    {
        $amount = $amountMinor / 100;
        $formatted = class_exists(\NumberFormatter::class)
            ? (new \NumberFormatter('pt_BR', \NumberFormatter::CURRENCY))->formatCurrency($amount, $currency)
            : $currency . ' ' . number_format($amount, 2, ',', '.');
        return $formatted . ' / ' . (PriceMap::LABELS[$cycle] ?? $cycle);
    }

    /** @return array<string,string>|null reference => uuid, null = acquisition failure */
    private static function planUuidsForServer(int $serverId): ?array
    {
        try {
            $client = ServerConfig::clientForServerId($serverId);
            if ($client === null) {
                return null;
            }
            $rows = $client->get('/partner/plans');
            $rows = is_array($rows['data'] ?? null) ? $rows['data'] : $rows;
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                $uuid = trim((string) ($row['uuid'] ?? ''));
                if ($id !== '' && self::isUuid($uuid)) {
                    $out[$id] = $uuid;
                }
            }
            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** @return array{ok:bool,reason:string,revision:string,count:int} */
    private static function result(bool $ok, string $reason): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'revision' => '', 'count' => 0];
    }
}
