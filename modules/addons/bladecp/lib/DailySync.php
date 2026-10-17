<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Daily structural synchronization: configurable options of linked
 * products, webhook ledger pruning, explicit Vibe upgrade tiers, catalog
 * availability and the Vibe commercial snapshot of each server.
 *
 * Idempotent and price preserving: existing pricing rows are never touched,
 * no group is created and new products are not linked automatically. An
 * unexpected error is left to the caller, which records its cause.
 */
final class DailySync
{
    /** @return array{servers:int,completed:int,skipped:int} */
    public static function run(): array
    {
        $summary = ['servers' => 0, 'completed' => 0, 'skipped' => 0];
        $contexts = AdminServerContext::candidates();
        if ($contexts === []) {
            self::log('BladeCP cron sync: skipped; server_id=0; reason=no-configured-server;');
            return $summary;
        }
        $components = ConfigurableOptions::syncAllLinked();
        $webhooksPruned = WebhookEventStore::prune(30);
        // This must precede the commercial snapshot so recurring legacy
        // Vibe products receive their explicit Pro tier in the same run.
        $nativeUpgrades = PlanSync::reconcileVibeNativeUpgrades();
        foreach ($contexts as $context) {
            $serverId = (int) ($context['id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }
            $summary['servers']++;
            $catalog = PlanSync::reconcileAvailabilityForServer($serverId);
            $commercialCatalog = VibeCommercialCatalog::syncForServer($serverId);
            $expired = CatalogAvailabilitySync::closeExpired($serverId, Provisioning::serverGroup($serverId));
            if (($catalog['ok'] ?? false) !== true) {
                $summary['skipped']++;
                self::log('BladeCP cron sync: skipped; server_id=' . $serverId . '; reason=catalog-'
                    . preg_replace('/[^a-z-]/', '', (string) ($catalog['reason'] ?? 'unavailable')) . ';');
                continue;
            }
            $summary['completed']++;
            self::log('BladeCP cron sync: completed; server_id=' . $serverId
                . '; catalog_processed=' . (int) ($catalog['processed'] ?? 0)
                . '; catalog_blocked=' . (int) ($catalog['blocked'] ?? 0)
                . '; components_ran=' . (!empty($components['ran']) ? '1' : '0')
                . '; expired=' . $expired
                . '; webhooks_pruned=' . $webhooksPruned . ';'
                . ' native_upgrades=' . (!empty($nativeUpgrades['ok']) ? '1' : '0') . ';'
                . ' commercial_catalog=' . ($commercialCatalog['ok'] ? '1' : '0') . ';');
        }
        return $summary;
    }

    private static function log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // Observability never stops the synchronization.
        }
    }
}
