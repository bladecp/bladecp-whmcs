<?php

/**
 * Hooks do ADDON BladeCP.
 *
 * As filas leves e a sincronização diária rodam no cron próprio do módulo
 * (cron.php, a cada minuto). Os hooks do cron do WHMCS só socorrem esse
 * trabalho quando o cron próprio para: filas após 15 minutos sem sinal e o
 * trabalho diário após 36 horas sem sucesso. Em operação normal, eles saem
 * na hora. Os hooks de evento continuam imediatos.
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

use WHMCS\Module\Addon\Bladecp\DailySync;
use WHMCS\Module\Addon\Bladecp\ModuleCron;
use WHMCS\Module\Addon\Bladecp\ModuleCronAlert;
use WHMCS\Module\Addon\Bladecp\VibeServiceIdentityCta;
use WHMCS\Module\Addon\Bladecp\VibeSupportAdminCta;
use WHMCS\Module\Addon\Bladecp\VibePromptCard;
use WHMCS\Module\Addon\Bladecp\FastDeployClientArea;
use WHMCS\Module\Server\Bladecp\Ui\OrderFormSkin;

if (function_exists('add_hook')) {
    add_hook('UserLogout', 1, static function ($vars): void {
        \WHMCS\Module\Server\Bladecp\Helpers\FederationLogout::onLogout(is_array($vars) ? $vars : []);
    });
    add_hook('AdminLogout', 1, static function ($vars): void {
        \WHMCS\Module\Server\Bladecp\Helpers\FederationLogout::onAdminLogout(is_array($vars) ? $vars : []);
    });
    add_hook('AfterCronJob', 1, static function (): void {
        if (class_exists(ModuleCron::class)) {
            // Rescue only: returns at once while the module cron is alive.
            try { ModuleCron::rescueQueues(); } catch (\Throwable) { }
            // Watches the module cron from outside it.
            try { ModuleCronAlert::run(); } catch (\Throwable) { }
            return;
        }
        try { \WHMCS\Module\Server\Bladecp\Helpers\FederationLogout::flush(); } catch (\Throwable) { }
        try { \WHMCS\Module\Addon\Bladecp\VibeWelcomeQueue::flush(); } catch (\Throwable) { }
    });
    add_hook('AfterModuleCreate', 1, static function ($vars): void {
        $params = is_array($vars) && is_array($vars['params'] ?? null) ? $vars['params'] : [];
        $serviceId = filter_var($params['serviceid'] ?? null, FILTER_VALIDATE_INT);
        if ($serviceId !== false && $serviceId > 0) {
            // Delivery runs after the native status transition and native
            // welcome handling, never during provisioning or an SSO redirect.
            \WHMCS\Module\Addon\Bladecp\VibeWelcomeQueue::enqueue((int) $serviceId);
        }
    });
    OrderFormSkin::registerHook();
    VibeServiceIdentityCta::registerHook();
    VibeSupportAdminCta::registerHook();
    VibePromptCard::registerHook();
    FastDeployClientArea::registerHook();
    if (method_exists(\WHMCS\Module\Addon\Bladecp\WordPress\ClientArea::class, 'registerHook')) {
        \WHMCS\Module\Addon\Bladecp\WordPress\ClientArea::registerHook();
    }
    add_hook('InvoicePaid', 2, static function (): void {
        try { \WHMCS\Module\Addon\Bladecp\FastDeploy\Expiry::run(); } catch (\Throwable) { }
    });

    add_hook('DailyCronJob', 1, static function ($vars): void {
        try {
            if (class_exists(ModuleCron::class)) {
                // Rescue only: runs when the daily work has not succeeded for
                // 36 hours, since the module cron normally runs it.
                ModuleCron::rescueDaily();
                return;
            }
            DailySync::run();
        } catch (\Throwable) {
            bladecp_cron_log('BladeCP cron sync: failed; server_id=0; reason=unexpected;');
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
