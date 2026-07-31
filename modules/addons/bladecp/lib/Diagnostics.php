<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Exceptions\AuthException;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Diagnóstico administrativo somente leitura.
 */
final class Diagnostics
{
    /** @var list<string> */
    private const CATALOG_CACHE_NAMES = [
        'bladecp_components_catalog.json',
        'bladecp_components_catalog_full_v5.json',
    ];

    /**
     * @return array{overall:string,checks:list<array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string}>}
     */
    public static function snapshot(): array
    {
        $checks = [
            self::credential(),
            self::apiConnectivity(),
            self::receiverUrl(),
            self::webhookConfiguration(),
            self::catalogFreshness(),
            self::cronFreshness(),
            self::productIntegrity(),
        ];
        $states = array_column($checks, 'state');
        $overall = in_array('blocked', $states, true) ? 'blocked'
            : (in_array('attention', $states, true) ? 'attention'
                : (in_array('unknown', $states, true) ? 'unknown' : 'ok'));
        return ['overall' => $overall, 'checks' => $checks];
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function credential(): array
    {
        $configured = self::params() !== null;
        return self::check(
            'credential',
            $configured ? 'ok' : 'blocked',
            I18n::t('viewmodels.diagnostics.credential.title'),
            $configured
                ? I18n::t('viewmodels.diagnostics.credential.configuredDetail')
                : I18n::t('viewmodels.diagnostics.credential.notConfiguredDetail'),
            $configured
                ? I18n::t('viewmodels.diagnostics.credential.configuredAction')
                : I18n::t('viewmodels.diagnostics.credential.notConfiguredAction')
        );
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function apiConnectivity(): array
    {
        $client = self::client();
        if ($client === null) {
            return self::check('api', 'blocked', I18n::t('viewmodels.diagnostics.api.title'), I18n::t('viewmodels.diagnostics.api.serverNotConfiguredDetail'), I18n::t('viewmodels.diagnostics.api.serverNotConfiguredAction'));
        }
        try {
            // Uma consulta pequena valida DNS, TLS e autorização sem escrever
            // nada nem carregar o catálogo completo.
            $client->get('/partner/plans?limit=1');
            return self::check('api', 'ok', I18n::t('viewmodels.diagnostics.api.title'), I18n::t('viewmodels.diagnostics.api.okDetail'), I18n::t('viewmodels.diagnostics.actionNone'));
        } catch (AuthException) {
            return self::check('api', 'blocked', I18n::t('viewmodels.diagnostics.api.title'), I18n::t('viewmodels.diagnostics.api.authRejectedDetail'), I18n::t('viewmodels.diagnostics.api.authRejectedAction'));
        } catch (\Throwable) {
            return self::check('api', 'unknown', I18n::t('viewmodels.diagnostics.api.title'), I18n::t('viewmodels.diagnostics.api.unknownDetail'), I18n::t('viewmodels.diagnostics.api.unknownAction'));
        }
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function receiverUrl(): array
    {
        if (!class_exists(WebhookConfig::class)) {
            return self::check('receiver_url', 'unknown', I18n::t('viewmodels.diagnostics.receiverUrl.title'), I18n::t('viewmodels.diagnostics.receiverUrl.unavailableDetail'), I18n::t('viewmodels.diagnostics.openAddonAction'));
        }
        $status = WebhookConfig::classifyPublicUrl();
        return self::check('receiver_url', $status['state'], I18n::t('viewmodels.diagnostics.receiverUrl.title'), $status['detail'], $status['action']);
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function webhookConfiguration(): array
    {
        if (!class_exists(WebhookConfig::class)) {
            return self::check('webhook', 'unknown', I18n::t('viewmodels.diagnostics.webhook.title'), I18n::t('viewmodels.diagnostics.webhook.unavailableDetail'), I18n::t('viewmodels.diagnostics.openAddonAction'));
        }
        $endpoint = WebhookConfig::getEndpointId() !== '';
        $secret = WebhookConfig::hasSecret();
        if ($endpoint && $secret) {
            return self::check('webhook', 'ok', I18n::t('viewmodels.diagnostics.webhook.title'), I18n::t('viewmodels.diagnostics.webhook.okDetail'), I18n::t('viewmodels.diagnostics.actionNone'));
        }
        if (!$endpoint && !$secret) {
            return self::check('webhook', 'attention', I18n::t('viewmodels.diagnostics.webhook.title'), I18n::t('viewmodels.diagnostics.webhook.notConnectedDetail'), I18n::t('viewmodels.diagnostics.webhook.notConnectedAction'));
        }
        return self::check('webhook', 'blocked', I18n::t('viewmodels.diagnostics.webhook.title'), I18n::t('viewmodels.diagnostics.webhook.incompleteDetail'), I18n::t('viewmodels.diagnostics.webhook.incompleteAction'));
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function catalogFreshness(): array
    {
        $client = self::client();
        if ($client === null || !class_exists(CacheStore::class)) {
            return self::check('catalog', 'unknown', I18n::t('viewmodels.diagnostics.catalog.title'), I18n::t('viewmodels.diagnostics.catalog.notObservedDetail'), I18n::t('viewmodels.diagnostics.catalog.loadAction'));
        }

        foreach (self::CATALOG_CACHE_NAMES as $name) {
            if (CacheStore::read($client, $name, 3600) !== null) {
                return self::check('catalog', 'ok', I18n::t('viewmodels.diagnostics.catalog.title'), I18n::t('viewmodels.diagnostics.catalog.freshDetail'), I18n::t('viewmodels.diagnostics.actionNone'));
            }
        }
        foreach (self::CATALOG_CACHE_NAMES as $name) {
            if (CacheStore::read($client, $name, PHP_INT_MAX) !== null) {
                return self::check('catalog', 'attention', I18n::t('viewmodels.diagnostics.catalog.title'), I18n::t('viewmodels.diagnostics.catalog.staleDetail'), I18n::t('viewmodels.diagnostics.catalog.staleAction'));
            }
        }
        return self::check('catalog', 'unknown', I18n::t('viewmodels.diagnostics.catalog.title'), I18n::t('viewmodels.diagnostics.catalog.notObservedDetail'), I18n::t('viewmodels.diagnostics.catalog.loadAction'));
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function cronFreshness(): array
    {
        $serverId = self::managementServerId();
        if ($serverId === null) {
            return self::check('cron', 'unknown', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.serverUnavailableDetail'), I18n::t('viewmodels.diagnostics.cron.serverUnavailableAction'));
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::check('cron', 'unknown', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.historyUnavailableDetail'), I18n::t('viewmodels.diagnostics.cron.historyUnavailableAction'));
        }
        try {
            $row = \WHMCS\Database\Capsule::table('tblactivitylog')
                ->where('description', 'like', 'BladeCP cron sync:%')
                ->where('description', 'like', '%server_id=' . $serverId . ';%')
                ->orderByDesc('id')
                ->first();
            $date = $row === null ? '' : trim((string) ($row->date ?? ''));
            $timestamp = $date !== '' ? strtotime($date) : false;
            if ($timestamp === false) {
                return self::check('cron', 'unknown', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.notObservedDetail'), I18n::t('viewmodels.diagnostics.cron.notObservedAction'));
            }
            $description = trim((string) ($row->description ?? ''));
            if (str_starts_with($description, 'BladeCP cron sync: failed;')) {
                return self::check('cron', 'attention', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.failedDetail'), I18n::t('viewmodels.diagnostics.cron.failedAction'));
            }
            if (str_starts_with($description, 'BladeCP cron sync: skipped;')) {
                return self::check('cron', 'attention', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.skippedDetail'), I18n::t('viewmodels.diagnostics.cron.skippedAction'));
            }
            if (time() - $timestamp <= 172800
                && str_starts_with($description, 'BladeCP cron sync: completed;')) {
                return self::check('cron', 'ok', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.okDetail'), I18n::t('viewmodels.diagnostics.actionNone'));
            }
            if (str_starts_with($description, 'BladeCP cron sync: completed;')) {
                return self::check('cron', 'attention', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.staleDetail'), I18n::t('viewmodels.diagnostics.cron.staleAction'));
            }
            return self::check('cron', 'unknown', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.unrecognisedDetail'), I18n::t('viewmodels.diagnostics.cron.unrecognisedAction'));
        } catch (\Throwable) {
            return self::check('cron', 'unknown', I18n::t('viewmodels.diagnostics.cron.title'), I18n::t('viewmodels.diagnostics.cron.queryFailedDetail'), I18n::t('viewmodels.diagnostics.cron.historyUnavailableAction'));
        }
    }

    /** @return array{id:string,state:string,title:string,detail:string,action:string,checkedAt:string} */
    private static function productIntegrity(): array
    {
        $scope = class_exists(IntegrationReadiness::class)
            ? IntegrationReadiness::scope() : null;
        if ($scope === null) {
            return self::check('integrity', 'unknown', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.scopeUnknownDetail'), I18n::t('viewmodels.diagnostics.integrity.scopeUnknownAction'));
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::check('integrity', 'unknown', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.databaseUnavailableDetail'), I18n::t('viewmodels.diagnostics.integrity.databaseUnavailableAction'));
        }
        try {
            $products = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('servergroup', $scope['serverGroupId'])
                ->get(['id', 'configoption1', 'configoption2']);
            $total = count($products);
            if ($total === 0) {
                return self::check('integrity', 'unknown', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.noProductsDetail'), I18n::t('viewmodels.diagnostics.integrity.noProductsAction'));
            }
            $invalid = 0;
            foreach ($products as $product) {
                if (trim((string) ($product->configoption1 ?? '')) === '' || trim((string) ($product->configoption2 ?? '')) === '') {
                    $invalid++;
                }
            }
            if ($invalid === 0) {
                return self::check('integrity', 'ok', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.okDetail', ['count' => $total]), I18n::t('viewmodels.diagnostics.actionNone'));
            }
            return self::check('integrity', 'attention', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.invalidDetail', ['invalid' => $invalid, 'total' => $total]), I18n::t('viewmodels.diagnostics.integrity.invalidAction'));
        } catch (\Throwable) {
            return self::check('integrity', 'unknown', I18n::t('viewmodels.diagnostics.integrity.title'), I18n::t('viewmodels.diagnostics.integrity.queryFailedDetail'), I18n::t('viewmodels.diagnostics.integrity.queryFailedAction'));
        }
    }

    /** @return array<string,string> */
    private static function check(string $id, string $state, string $title, string $detail, string $action): array
    {
        return ['id' => $id, 'state' => $state, 'title' => $title, 'detail' => $detail, 'action' => $action, 'checkedAt' => gmdate('c')];
    }

    private static function params(): ?array
    {
        try {
            return class_exists(ServerConfig::class) ? ServerConfig::params() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function client(): ?\WHMCS\Module\Server\Bladecp\Api\Client
    {
        try {
            return class_exists(ServerConfig::class) ? ServerConfig::client() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Diagnóstico global não escolhe automaticamente o primeiro servidor. */
    private static function managementServerId(): ?int
    {
        if (!class_exists(AdminServerContext::class)) {
            return null;
        }
        try {
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            return ($context['state'] ?? '') === 'configured' && $serverId > 0
                ? $serverId : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
