<?php

/**
 * Hooks do ADDON BladeCP.
 *
 * Sincronização estrutural diária pelo `DailyCronJob`, disparado pelo cron de
 * sistema do WHMCS. Não requer cron próprio. A cada execução reaplica a
 * configuração do grupo
 * de Configurable Options aos produtos JÁ vinculados → componente
 * novo no catálogo BladeCP aparece sozinho no order form do cliente.
 *
 * Idempotente e preservador de preços: `reconcileSubs` e `ensurePricing` não
 * tocam `tblpricing` existente. É reversível e fail-soft. Não cria grupo nem
 * vincula automaticamente produtos novos, pois a sincronização inicial é
 * manual.
 *
 * Entrypoint separado do WHMCS e independente da ordem de load do
 * bladecp.php. O bootstrap dual-prefix preserva a ponte addon + server.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../servers/bladecp/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerAddonAutoloaders(
    __DIR__,
    __DIR__ . '/../../servers/bladecp'
);

use WHMCS\Module\Addon\Bladecp\ConfigurableOptions;
use WHMCS\Module\Addon\Bladecp\CatalogAvailabilitySync;
use WHMCS\Module\Addon\Bladecp\PlanSync;
use WHMCS\Module\Addon\Bladecp\Provisioning;
use WHMCS\Module\Addon\Bladecp\WebhookEventStore;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Ui\OrderFormSkin;

if (function_exists('add_hook')) {
    OrderFormSkin::registerHook();

    add_hook('DailyCronJob', 1, static function ($vars): void {
        try {
            $contexts = AdminServerContext::candidates();
            if ($contexts === []) {
                bladecp_cron_log(
                    'BladeCP cron sync: skipped; server_id=0; reason=no-configured-server;'
                );
                return;
            }
            $components = ConfigurableOptions::syncAllLinked();
            $webhooksPruned = WebhookEventStore::prune(30);
            foreach ($contexts as $context) {
                $serverId = (int) ($context['id'] ?? 0);
                if ($serverId <= 0) {
                    continue;
                }
                $catalog = PlanSync::reconcileAvailabilityForServer($serverId);
                $expired = CatalogAvailabilitySync::closeExpired(
                    $serverId,
                    Provisioning::serverGroup($serverId)
                );
                if (($catalog['ok'] ?? false) !== true) {
                    bladecp_cron_log(
                        'BladeCP cron sync: skipped; server_id=' . $serverId
                        . '; reason=catalog-'
                        . preg_replace('/[^a-z-]/', '', (string) ($catalog['reason'] ?? 'unavailable'))
                        . ';'
                    );
                    continue;
                }
                bladecp_cron_log(
                    'BladeCP cron sync: completed; server_id=' . $serverId
                    . '; catalog_processed=' . (int) ($catalog['processed'] ?? 0)
                    . '; catalog_blocked=' . (int) ($catalog['blocked'] ?? 0)
                    . '; components_ran=' . (!empty($components['ran']) ? '1' : '0')
                    . '; expired=' . $expired
                    . '; webhooks_pruned=' . $webhooksPruned . ';'
                );
            }
        } catch (\Throwable) {
            bladecp_cron_log(
                'BladeCP cron sync: failed; server_id=0; reason=unexpected;'
            );
        }
    });
}

/** Registro curto, estruturado e seguro para o diagnóstico do addon. */
if (!function_exists('bladecp_cron_log')) {
    function bladecp_cron_log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // O cron nativo do WHMCS não pode falhar por causa de observabilidade.
        }
    }
}
