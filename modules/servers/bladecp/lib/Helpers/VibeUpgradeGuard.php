<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Rejects Vibe Pro to Free changes while they are still in the checkout flow. */
final class VibeUpgradeGuard
{

    /** @param array<string,mixed> $vars */
    public static function errorForUpgrade(array $vars): ?string
    {
        $serviceId = self::positiveInteger($vars['serviceid'] ?? $vars['service_id'] ?? null);
        $targetProductId = self::positiveInteger(
            $vars['newproductid'] ?? $vars['newpid'] ?? $vars['productid'] ?? $vars['pid'] ?? null
        );
        if ($serviceId === null || $targetProductId === null || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }

        try {
            $source = self::sourceProduct($serviceId);
            if ($source === null) {
                return null;
            }
            $target = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $targetProductId)->first();
            if ($target === null
                || (string) ($target->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($target->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $destination = VibePlanTier::normalize($target->configoption4 ?? null);
            if ($destination !== VibePlanTier::FREE) {
                return null;
            }

            $current = self::currentTierForService($serviceId);
            return VibePlanTier::isForbiddenDowngrade($current, $destination) ? ClientText::get('vibe_downgrade_unavailable') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Resolves persisted service tier first, then the source Vibe product tier. */
    public static function currentTierForService(int $serviceId): ?string
    {
        if ($serviceId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }

        try {
            $field = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->where('fieldname', 'BladeCP Vibe Plan Tier')
                ->first();
            if ($field !== null && (int) ($field->id ?? 0) > 0) {
                $value = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                    ->where('fieldid', (int) $field->id)
                    ->where('relid', $serviceId)
                    ->first();
                $tier = VibePlanTier::normalize($value->value ?? null);
                if ($tier !== null) {
                    return $tier;
                }
            }
            $source = self::sourceProduct($serviceId);
            return $source === null ? null : VibePlanTier::normalize($source->configoption4 ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sourceProduct(int $serviceId): ?object
    {
        $schema = \WHMCS\Database\Capsule::schema();
        if (!$schema->hasTable('tblhosting')
            || !$schema->hasColumn('tblhosting', 'packageid')
            || !$schema->hasTable('tblproducts')
            || !$schema->hasColumn('tblproducts', 'configoption4')) {
            return null;
        }
        $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $sourceProductId = self::positiveInteger($service->packageid ?? null);
        if ($sourceProductId === null) {
            return null;
        }
        $source = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $sourceProductId)->first();
        if ($source === null
            || (string) ($source->servertype ?? '') !== 'bladecp'
            || strtolower(trim((string) ($source->configoption1 ?? ''))) !== 'vibe') {
            return null;
        }

        return $source;
    }

    private static function positiveInteger(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0
            ? (int) $value
            : null;
    }
}
