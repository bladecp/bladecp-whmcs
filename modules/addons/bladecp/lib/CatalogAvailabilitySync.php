<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Reconcilia a disponibilidade observada com a vitrine WHMCS.
 *
 * O único efeito em tabelas nativas é `tblproducts.hidden = 1` quando a
 * venda deixa de ser segura. Não há deleção e não há reexposição automática.
 */
final class CatalogAvailabilitySync
{
    public const SETTING_MODE = 'catalog_availability_mode';
    public const MODE_OBSERVE = 'observe';
    public const MODE_ENFORCE = 'enforce';

    /** O padrão deliberado registra e protege, mas não muda a vitrine. */
    public static function mode(): string
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::MODE_OBSERVE;
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', self::SETTING_MODE)
                ->first();
            return ($row !== null && ($row->value ?? '') === self::MODE_ENFORCE)
                ? self::MODE_ENFORCE : self::MODE_OBSERVE;
        } catch (\Throwable) {
            return self::MODE_OBSERVE;
        }
    }

    /** Não existe modo desligado: a decisão de venda sempre é registrada. */
    public static function setMode(string $mode): bool
    {
        $mode = $mode === self::MODE_ENFORCE ? self::MODE_ENFORCE : self::MODE_OBSERVE;
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            \WHMCS\Database\Capsule::connection()->transaction(static function () use ($mode): void {
                \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->where('module', 'bladecp')
                    ->where('setting', self::SETTING_MODE)
                    ->delete();
                \WHMCS\Database\Capsule::table('tbladdonmodules')->insert([
                    'module' => 'bladecp',
                    'setting' => self::SETTING_MODE,
                    'value' => $mode,
                ]);
            });
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{ok:bool,processed:int,hidden:int,blocked:int,reason:string} */
    public static function reconcileCatalog(
        array $plans,
        ?int $serverId = null,
        ?int $serverGroupId = null
    ): array
    {
        if ($serverId === null || $serverId <= 0 || $serverGroupId === null || $serverGroupId <= 0) {
            return ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'server-unavailable'];
        }
        if ($plans === []) {
            return ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'catalog-unavailable'];
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'no-capsule'];
        }
        try {
            $byPlan = [];
            foreach ($plans as $plan) {
                if (!is_array($plan)) {
                    continue;
                }
                $id = trim((string) ($plan['id'] ?? ''));
                if ($id !== '') {
                    $byPlan[$id] = $plan;
                }
            }
            if ($byPlan === []) {
                return ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'catalog-invalid'];
            }
            $processed = 0;
            $hidden = 0;
            $blocked = 0;
            $products = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('servergroup', $serverGroupId)
                ->where('configoption2', '!=', '')
                ->get(['id', 'configoption2', 'hidden']);
            foreach ($products as $product) {
                $plan = $byPlan[(string) $product->configoption2] ?? null;
                if ($plan === null) {
                    $state = CatalogStateRepository::closeSale(
                        (int) $product->id,
                        'plan-not-available',
                        [[
                            'code' => 'plan_not_available',
                            'retryable' => false,
                            'action' => 'review_catalog',
                        ]]
                    );
                    $wasHidden = false;
                    if (self::mode() === self::MODE_ENFORCE && (int) ($product->hidden ?? 0) !== 1) {
                        $wasHidden = self::hideProduct((int) $product->id);
                        if ($wasHidden) {
                            CatalogStateRepository::markHiddenByModule((int) $product->id);
                        }
                    }
                    $processed++;
                    $blocked++;
                    $hidden += $wasHidden ? 1 : 0;
                    continue;
                }
                $result = self::reconcileProduct(
                    (int) $product->id,
                    (string) $product->configoption2,
                    $plan,
                    (int) ($product->hidden ?? 0) === 1,
                    $serverId,
                    $serverGroupId
                );
                $processed++;
                $hidden += $result['hidden'] ? 1 : 0;
                $blocked += $result['blocked'] ? 1 : 0;
            }
            return ['ok' => true, 'processed' => $processed, 'hidden' => $hidden, 'blocked' => $blocked, 'reason' => ''];
        } catch (\Throwable) {
            return ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'reconcile-failed'];
        }
    }

    /** Alias curto para a UI e jobs de reconciliação. */
    public static function reconcile(array $plans, ?int $serverId = null, ?int $serverGroupId = null): array
    {
        return self::reconcileCatalog($plans, $serverId, $serverGroupId);
    }

    /** @return array<string,mixed> */
    public static function summary(int $serverId, int $serverGroupId): array
    {
        return [
            'mode' => self::mode(),
            'catalog' => CatalogStateRepository::summary($serverId, $serverGroupId),
        ];
    }

    /**
     * @param array<string,mixed> $plan
     * @return array{hidden:bool,blocked:bool,state:?array}
     */
    public static function reconcileProduct(
        int $productId,
        string $planId,
        array $plan,
        bool $alreadyHidden = false,
        ?int $serverId = null,
        ?int $serverGroupId = null
    ): array {
        $availability = $plan['availability'] ?? null;
        if (!is_array($availability)) {
            $availability = [
                'schemaVersion' => 0,
                'salesEligible' => false,
                'readiness' => 'unknown',
                'blockers' => [[
                    'code' => 'availability_unknown',
                    'retryable' => true,
                    'action' => 'refresh_catalog',
                ]],
                'checkedAt' => date(DATE_ATOM),
            ];
        }
        $state = CatalogStateRepository::observe($productId, $planId, $availability, $serverId, $serverGroupId);
        $blocked = $state === null || !$state['effectiveSalesEligible'];
        $hidden = false;
        if ($blocked) {
            CatalogStateRepository::closeSale(
                $productId,
                (string) ($state['effectiveReason'] ?? 'not-eligible'),
                is_array($state['effectiveBlockers'] ?? null) ? $state['effectiveBlockers'] : []
            );
            if (self::mode() === self::MODE_ENFORCE && !$alreadyHidden) {
                $hidden = self::hideProduct($productId);
                if ($hidden) {
                    CatalogStateRepository::markHiddenByModule($productId);
                }
            }
        }
        return ['hidden' => $hidden, 'blocked' => $blocked, 'state' => $state];
    }

    /** Revalida TTLs já persistidos, sem depender de resposta remota nova. */
    public static function closeExpired(?int $serverId = null, ?int $serverGroupId = null): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class) || $serverId === null || $serverId <= 0 || $serverGroupId === null || $serverGroupId <= 0) {
            return 0;
        }
        $closed = 0;
        foreach (CatalogStateRepository::all($serverId, $serverGroupId) as $state) {
            $until = $state['validUntil'] ?? null;
            if (!is_string($until) || $until === '') {
                continue;
            }
            if (
                empty($state['effectiveSalesEligible'])
                && (string) ($state['effectiveReason'] ?? '') === 'availability-stale'
            ) {
                continue;
            }
            try {
                if ((new \DateTimeImmutable($until)) > new \DateTimeImmutable('now')) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            CatalogStateRepository::closeSale((int) $state['productId'], 'availability-stale', [[
                'code' => 'availability_stale',
                'retryable' => true,
                'action' => 'refresh_catalog',
            ]]);
            if (self::mode() === self::MODE_ENFORCE && self::hideProduct((int) $state['productId'])) {
                CatalogStateRepository::markHiddenByModule((int) $state['productId']);
            }
            $closed++;
        }
        return $closed;
    }

    private static function hideProduct(int $productId): bool
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            return \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->where('hidden', '!=', 1)
                ->update(['hidden' => 1]) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
