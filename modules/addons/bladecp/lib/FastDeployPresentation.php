<?php

declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Client-facing resource and native WHMCS base-price projection. */
final class FastDeployPresentation
{
    public static function products(array $products, array $copy): array
    {
        $currencyId = null;
        try {
            $identity = IdentityHandoff::currentIdentity();
            if ($identity !== null && function_exists('getCurrency') && function_exists('formatCurrency')) {
                $currency = getCurrency($identity['clientId']);
                $currencyId = (int) ($currency['id'] ?? 0);
            }
        } catch (\Throwable) { /* Missing pricing must not hide resource information. */ }
        foreach ($products as &$product) {
            $resources = $product['resources'] ?? [];
            $product['specs'] = [
                'cpu' => self::quantity($resources['cpu'] ?? null, 'vCPU', $copy),
                'ram' => self::quantity($resources['ramGb'] ?? null, 'GB', $copy),
                'disk' => self::quantity($resources['diskGb'] ?? null, 'GB', $copy),
            ];
            $product['price'] = $copy['price_unavailable'];
            $product['setup'] = '';
            $product['priceLabel'] = $copy[$product['policy']['mode'] === 'trial' ? 'price_after_trial' : 'base_price'];
            $product['policyLabel'] = str_replace('{days}', (string) ($product['policy']['trial_days'] ?? ''), $copy['badge_' . $product['policy']['mode']]);
            if (!$currencyId) { continue; }
            try {
                $row = Capsule::table('tblpricing')->where('type', 'product')->where('relid', $product['id'])->where('currency', $currencyId)->first();
                $price = self::basePrice($row === null ? [] : (array) $row);
                if ($price !== null) {
                    $product['price'] = (string) formatCurrency($price['amount'], $currencyId) . ' / ' . $copy['cycle_' . $price['cycle']];
                    if ($price['setup'] > 0) {
                        $product['setup'] = $copy['setup_fee'] . ': ' . (string) formatCurrency($price['setup'], $currencyId);
                    }
                }
            } catch (\Throwable) { /* No price from a different currency or remote catalog. */ }
        }
        return $products;
    }

    public static function icon(string $field): string
    {
        $path = match ($field) {
            'cpu' => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3m6-3v3M9 19v3m6-3v3M2 9h3m-3 6h3m14-6h3m-3 6h3"/>',
            'ram' => '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M6 9v4m4-4v4m4-4v4m4-4v4M6 17v3m4-3v3m4-3v3m4-3v3"/>',
            'disk' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
            default => '<path d="M3 3h9l9 9-9 9-9-9V3Z"/><circle cx="8" cy="8" r="1"/>',
        };
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    public static function basePrice(array $row): ?array
    {
        foreach (PriceMap::SETUP_MAP as $cycle => $setupField) {
            $value = $row[$cycle] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) { continue; }
            return ['amount' => (float) $value, 'cycle' => $cycle, 'setup' => max(0, (float) ($row[$setupField] ?? 0))];
        }
        return null;
    }

    private static function quantity(mixed $value, string $unit, array $copy): string
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0
            ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') . ' ' . $unit
            : $copy['not_informed'];
    }
}
