<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Read-only destination catalog. Selection never authorizes provisioning. */
final class FastDeployDestinations
{
    public static function load(int $serverId): array
    {
        $products = FastDeployPolicy::choices($serverId);
        if ($products === []) {
            return ['state' => 'empty', 'products' => []];
        }
        try {
            $client = ServerConfig::clientForServerId($serverId);
            $responses = $client?->getMany(['/partner/plans', '/partner/locations']);
            if (!is_array($responses) || !is_array($responses[0] ?? null) || !is_array($responses[1] ?? null)) {
                return self::unavailable($products);
            }
            return self::catalog($products, $responses[0], $responses[1], time());
        } catch (\Throwable) {
            return self::unavailable($products);
        }
    }

    private static function unavailable(array $products): array
    {
        foreach ($products as &$product) { $product['locations'] = []; }
        return ['state' => 'unavailable', 'products' => $products];
    }

    /** Intersect native product allowlist with the authenticated partner catalog. */
    public static function catalog(array $products, array $plans, array $locations, int $now): array
    {
        $eligible = [];
        $resources = [];
        foreach ($plans as $plan) {
            if (!is_array($plan) || !is_int($plan['id'] ?? null) || $plan['id'] <= 0) { continue; }
            $availability = $plan['availability'] ?? null;
            if (!is_array($availability)) { continue; }
            $expires = is_string($availability['validUntil'] ?? null) ? strtotime($availability['validUntil']) : false;
            if (($plan['migration']['destinationEligible'] ?? null) === true
                && ($plan['customerApiProductType'] ?? null) === 'stack-container'
                && ($plan['customerApiProvisioningSupported'] ?? null) === true
                && ($availability['schemaVersion'] ?? null) === 1
                && ($availability['salesEligible'] ?? null) === true
                && $expires !== false && $expires > $now) {
                $eligible[$plan['id']] = true;
                $resources[$plan['id']] = is_array($plan['limits'] ?? null) ? $plan['limits'] : [];
            }
        }
        $byPlan = [];
        foreach ($locations as $location) {
            if (!is_array($location) || !is_int($location['id'] ?? null) || $location['id'] <= 0
                || !is_int($location['planId'] ?? null) || !isset($eligible[$location['planId']])) { continue; }
            $parts = [];
            foreach (['city', 'region', 'countryCode'] as $field) {
                if (is_string($location[$field] ?? null) && trim($location[$field]) !== '') {
                    $parts[] = trim($location[$field]);
                }
            }
            if ($parts === []) { continue; }
            $byPlan[$location['planId']][$location['id']] = ['id' => $location['id'], 'name' => implode(' · ', array_unique($parts))];
        }
        $hasDestination = false;
        foreach ($products as &$product) {
            $product['resources'] = $resources[$product['planId']] ?? [];
            $product['locations'] = $byPlan[$product['planId']] ?? [];
            $hasDestination = $hasDestination || $product['locations'] !== [];
        }
        return ['state' => $hasDestination ? 'ready' : 'empty', 'products' => $products];
    }

    /** Browser identifiers are selectors only; both must match the fresh catalog. */
    public static function selection(array $catalog, array $query): ?array
    {
        $id = $query['product_id'] ?? null;
        if (!is_string($id) || !ctype_digit($id) || !isset($catalog['products'][(int) $id])) { return null; }
        $product = $catalog['products'][(int) $id];
        $locationId = is_array($query['location_id'] ?? null)
            ? ($query['location_id'][(int) $id] ?? null) : ($query['location_id'] ?? null);
        if (!is_string($locationId) || !ctype_digit($locationId) || !isset($product['locations'][(int) $locationId])) { return null; }
        return ['product' => $product, 'location' => $product['locations'][(int) $locationId]];
    }
}
