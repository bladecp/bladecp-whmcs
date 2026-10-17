<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Reconciles the native WHMCS package-upgrade graph for Vibe products.
 *
 * Only edges where both endpoints are BladeCP Vibe products belong to this
 * synchronizer. This leaves every manual relation involving another product
 * untouched.
 */
final class VibeNativeUpgradeSync
{
    /**
     * @return array{ok:bool,added:int,removed:int,retained:int,reason:?string}
     */
    public static function reconcile(): array
    {
        $empty = ['ok' => false, 'added' => 0, 'removed' => 0, 'retained' => 0, 'reason' => null];
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            $empty['reason'] = 'no-capsule';
            return $empty;
        }

        try {
            $result = \WHMCS\Database\Capsule::connection()->transaction(
                static fn (): array => self::reconcileLocked()
            );
            if (is_array($result)) {
                return $result;
            }
            $empty['reason'] = 'transaction-unavailable';
            return $empty;
        } catch (\Throwable) {
            $empty['reason'] = 'reconcile-failed';
            return $empty;
        }
    }

    /**
     * Runs while the transaction and Vibe product row locks are held.
     *
     * @return array{ok:bool,added:int,removed:int,retained:int,reason:?string}
     */
    private static function reconcileLocked(): array
    {
        $empty = ['ok' => false, 'added' => 0, 'removed' => 0, 'retained' => 0, 'reason' => null];
        $schema = \WHMCS\Database\Capsule::schema();
        if (!$schema->hasTable('tblproduct_upgrade_products')) {
            $empty['reason'] = 'no-upgrade-table';
            return $empty;
        }
        $productColumns = array_flip($schema->getColumnListing('tblproducts'));
        $upgradeColumns = array_flip($schema->getColumnListing('tblproduct_upgrade_products'));
        foreach (['id', 'gid', 'servergroup', 'servertype', 'configoption1'] as $column) {
            if (!isset($productColumns[$column])) {
                $empty['reason'] = 'missing-product-column';
                return $empty;
            }
        }
        foreach (['id', 'product_id', 'upgrade_product_id'] as $column) {
            if (!isset($upgradeColumns[$column])) {
                $empty['reason'] = 'missing-upgrade-column';
                return $empty;
            }
        }

        $select = ['id', 'gid', 'servergroup', 'servertype', 'configoption1'];
        foreach (['configoption4', 'paytype'] as $optional) {
            if (isset($productColumns[$optional])) {
                $select[] = $optional;
            }
        }
        $products = \WHMCS\Database\Capsule::table('tblproducts')
            ->where('servertype', 'bladecp')
            ->where('configoption1', 'vibe')
            ->lockForUpdate()
            ->get($select);
        $vibe = [];
        foreach ($products as $product) {
            if (
                strtolower(trim((string) ($product->servertype ?? ''))) !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe'
            ) {
                continue;
            }
            $id = (int) ($product->id ?? 0);
            if ($id <= 0) {
                continue;
            }
            $kind = self::kind($product);
            if ($kind === null) {
                continue;
            }
            if (
                $kind === 'paid'
                && isset($productColumns['configoption4'])
                && VibePlanTier::normalize($product->configoption4 ?? null) !== VibePlanTier::PRO
            ) {
                \WHMCS\Database\Capsule::table('tblproducts')
                    ->where('id', $id)
                    ->update(['configoption4' => VibePlanTier::PRO]);
            }
            $scope = (int) ($product->gid ?? 0) . ':' . (int) ($product->servergroup ?? 0);
            $vibe[$id] = ['scope' => $scope, 'kind' => $kind];
        }

        $desired = [];
        foreach ($vibe as $sourceId => $source) {
            foreach ($vibe as $targetId => $target) {
                if ($source['scope'] !== $target['scope'] || $target['kind'] !== 'paid') {
                    continue;
                }
                if ($source['kind'] === 'paid' && $sourceId === $targetId) {
                    continue;
                }
                $desired[$sourceId . ':' . $targetId] = [$sourceId, $targetId];
            }
        }

        $added = 0;
        $removed = 0;
        $retained = 0;
        $existing = \WHMCS\Database\Capsule::table('tblproduct_upgrade_products')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'upgrade_product_id']);
        $seen = [];
        foreach ($existing as $edge) {
            $sourceId = (int) ($edge->product_id ?? 0);
            $targetId = (int) ($edge->upgrade_product_id ?? 0);
            $edgeId = (int) ($edge->id ?? 0);
            $key = $sourceId . ':' . $targetId;
            if (!isset($vibe[$sourceId], $vibe[$targetId])) {
                continue;
            }
            if (isset($desired[$key]) && !isset($seen[$key])) {
                $seen[$key] = true;
                $retained++;
                continue;
            }
            \WHMCS\Database\Capsule::table('tblproduct_upgrade_products')
                ->where('id', $edgeId)
                ->delete();
            $removed++;
        }
        foreach ($desired as $key => [$sourceId, $targetId]) {
            if (isset($seen[$key])) {
                continue;
            }
            \WHMCS\Database\Capsule::table('tblproduct_upgrade_products')->insert([
                'product_id' => $sourceId,
                'upgrade_product_id' => $targetId,
            ]);
            $added++;
        }

        return ['ok' => true, 'added' => $added, 'removed' => $removed, 'retained' => $retained, 'reason' => null];
    }

    private static function kind(object $product): ?string
    {
        $tier = VibePlanTier::normalize($product->configoption4 ?? null);
        $paytype = strtolower(trim((string) ($product->paytype ?? '')));
        if ($tier === VibePlanTier::FREE || $paytype === 'free') {
            return 'free';
        }

        if ($tier === VibePlanTier::PRO) {
            return 'paid';
        }

        return $paytype === 'recurring' ? 'paid' : null;
    }
}
