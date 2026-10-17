<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\CrontabInstaller;
use WHMCS\Module\Addon\Bladecp\ModuleCron;
use WHMCS\Module\Addon\Bladecp\ModuleCronAlert;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Module cron panel of the Diagnostics tab: health, pending work, command and activation. */
final class ModuleCronView
{
    private const TASKS = ['federation_logout', 'vibe_welcome', 'billing_import', 'commercial', 'daily'];

    public static function render(string $base, ?CrontabInstaller $installer = null, ?int $now = null): string
    {
        $now ??= time();
        $status = ModuleCron::status();
        $heartbeat = (int) $status['heartbeatAt'];
        $ready = $heartbeat > 0 && $now - $heartbeat <= ModuleCron::QUEUE_STALE_SECONDS;
        $detail = $heartbeat <= 0
            ? I18n::t('diagnostics.cron.never')
            : I18n::t($ready ? 'diagnostics.cron.fresh' : 'diagnostics.cron.stale', ['time' => self::time($heartbeat)]);
        $pending = self::pending();
        $command = ModuleCron::command();
        // Rendering never runs crontab: it is read only when an administrator acts.
        $availability = ($installer ?? CrontabInstaller::system())->availability();

        $html = '<section class="bcp-diagnostic-section" id="bcp-module-cron" aria-labelledby="bcp-module-cron-title">'
            . self::flash()
            . '<div class="bcp-section-heading"><div><span class="bcp-eyebrow">' . Layout::escape(I18n::t('diagnostics.cron.eyebrow')) . '</span>'
            . '<h4 id="bcp-module-cron-title">' . Layout::escape(I18n::t('diagnostics.cron.title')) . '</h4>'
            . '<p>' . Layout::escape($detail) . '</p></div>'
            . '<span class="bcp-badge ' . ($ready ? 'is-ok' : 'is-warn') . '">'
            . Layout::escape(I18n::t($ready ? 'diagnostics.cron.active' : 'diagnostics.cron.attention')) . '</span></div>'
            . '<dl class="bcp-cron-details"><dt>' . Layout::escape(I18n::t('diagnostics.cron.last_run')) . '</dt><dd>'
            . Layout::escape($heartbeat > 0 ? self::time($heartbeat) : I18n::t('diagnostics.cron.none')) . '</dd>'
            . '<dt>' . Layout::escape(I18n::t('diagnostics.cron.pending')) . '</dt><dd>' . $pending['count'] . '</dd>'
            . '<dt>' . Layout::escape(I18n::t('diagnostics.cron.oldest')) . '</dt><dd>'
            . Layout::escape($pending['oldest'] ?? I18n::t('diagnostics.cron.none')) . '</dd></dl>'
            . self::tasks($status['tasks'])
            . '<label class="bcp-fg-l" for="bcp-module-cron-command">' . Layout::escape(I18n::t('diagnostics.cron.command_label')) . '</label>'
            . '<div class="bcp-cron-command"><code id="bcp-module-cron-command">' . Layout::escape($command) . '</code> '
            . '<button type="button" class="btn btn-default" data-bcp-copy="bcp-module-cron-command">'
            . Layout::escape(I18n::t('diagnostics.cron.copy')) . '</button></div>'
            . self::activation($base, $availability)
            . self::alerts($base)
            . '<script>(function(){document.querySelectorAll("[data-bcp-copy]").forEach(function(b){b.addEventListener("click",function(){'
            . 'var t=document.getElementById(b.getAttribute("data-bcp-copy"));if(!t){return;}var v=t.textContent||"";'
            . 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v);return;}'
            . 'var r=document.createRange();r.selectNodeContents(t);var s=window.getSelection();s.removeAllRanges();s.addRange(r);document.execCommand("copy");});});})();</script>'
            . '</section>';
        return $html;
    }

    /** Called after the native admin permission and CSRF checks. Returns the redirect query. */
    public static function handle(string $action, ?CrontabInstaller $installer = null): string
    {
        if ($action === 'module-cron-alerts') {
            return ModuleCronAlert::save((string) ($_POST['module_cron_alerts'] ?? '') === '1') ? '&cron=alerts_saved' : '&cron_error=failed';
        }
        $installer ??= CrontabInstaller::system();
        if (!in_array($action, ['module-cron-install', 'module-cron-remove'], true)) {
            return '&cron_error=failed';
        }
        $install = $action === 'module-cron-install';
        $result = $install ? $installer->install(ModuleCron::command()) : $installer->uninstall();
        if (($result['ok'] ?? false) !== true) {
            $error = in_array($result['error'] ?? '', ['proc_open', 'crontab', 'read_failed', 'write_failed', 'verify_failed'], true)
                ? (string) $result['error'] : 'failed';
            self::log('BladeCP module cron: automatic crontab ' . ($install ? 'activation' : 'removal') . ' failed; cause=' . $error . '.');
            return '&cron_error=' . $error;
        }
        if ($result['changed']) {
            self::log('BladeCP module cron: crontab line ' . ($install ? 'added' : 'removed') . ' by an administrator.');
        }
        return '&cron=' . ($install ? ($result['changed'] ? 'installed' : 'already_installed') : ($result['changed'] ? 'removed' : 'not_installed'));
    }

    /** @param array{available:bool,reason:string} $availability */
    private static function activation(string $base, array $availability): string
    {
        if (!$availability['available']) {
            return '<p class="bcp-experience-help">' . Layout::escape(I18n::t('diagnostics.cron.auto_unavailable_' . $availability['reason'])) . '</p>';
        }
        $button = static fn (string $action, string $label, string $class): string => '<form method="post" action="' . Layout::escape($base)
            . '" style="display:inline">' . Layout::token() . '<input type="hidden" name="action" value="' . $action . '">'
            . '<button type="submit" class="btn ' . $class . '">' . Layout::escape(I18n::t($label)) . '</button></form> ';
        return '<div class="bcp-cron-activation">' . $button('module-cron-install', 'diagnostics.cron.auto_install', 'btn-primary')
            . $button('module-cron-remove', 'diagnostics.cron.auto_remove', 'btn-default')
            . '<p class="bcp-experience-help">' . Layout::escape(I18n::t('diagnostics.cron.auto_help')) . '</p></div>';
    }

    private static function alerts(string $base): string
    {
        return '<form method="post" action="' . Layout::escape($base) . '" class="bcp-cron-alerts">' . Layout::token()
            . '<input type="hidden" name="action" value="module-cron-alerts">'
            . '<label><input type="checkbox" name="module_cron_alerts" value="1"' . (ModuleCronAlert::enabled() ? ' checked' : '') . '> '
            . Layout::escape(I18n::t('diagnostics.cron.alerts_label')) . '</label> '
            . '<button type="submit" class="btn btn-default btn-sm">' . Layout::escape(I18n::t('diagnostics.cron.save')) . '</button>'
            . '<p class="bcp-experience-help">' . Layout::escape(I18n::t('diagnostics.cron.alerts_help')) . '</p></form>';
    }

    /** @param array<string,array<string,mixed>> $tasks */
    private static function tasks(array $tasks): string
    {
        $rows = '';
        foreach (self::TASKS as $name) {
            $task = $tasks[$name] ?? [];
            $lastRun = (int) ($task['lastRunAt'] ?? 0);
            $lastError = (int) ($task['lastErrorAt'] ?? 0);
            $failing = $lastError > 0 && $lastError >= (int) ($task['lastOkAt'] ?? 0);
            $rows .= '<tr><td>' . Layout::escape(I18n::t('diagnostics.cron.task_' . $name)) . '</td>'
                . '<td>' . Layout::escape($lastRun > 0 ? self::time($lastRun) : I18n::t('diagnostics.cron.none')) . '</td>'
                . '<td>' . Layout::escape(self::result(is_array($task['lastResult'] ?? null) ? $task['lastResult'] : [])) . '</td>'
                . '<td>' . ($failing
                    ? '<span class="bcp-badge is-err">' . Layout::escape(I18n::t('diagnostics.cron.task_failed')) . '</span> '
                        . Layout::escape(self::time($lastError) . ': ' . (string) ($task['lastError'] ?? ''))
                    : Layout::escape($lastError > 0 ? I18n::t('diagnostics.cron.last_error_at', ['time' => self::time($lastError)]) : I18n::t('diagnostics.cron.no_error')))
                . '</td></tr>';
        }
        return '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">' . Layout::escape(I18n::t('diagnostics.cron.task')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('diagnostics.cron.last_run')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('diagnostics.cron.last_result')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('diagnostics.cron.last_error')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    /** @param array<string,mixed> $result */
    private static function result(array $result): string
    {
        $parts = [];
        foreach ($result as $key => $value) {
            if (is_string($key) && preg_match('/^[a-z_]{1,32}$/D', $key) === 1 && (is_int($value) || is_bool($value))) {
                $parts[] = $key . '=' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
            }
        }
        return $parts === [] ? '' : implode(', ', $parts);
    }

    /** @return array{count:int,oldest:?string} work waiting for the module cron in this WHMCS */
    private static function pending(): array
    {
        $count = 0;
        $oldest = null;
        try {
            if (Capsule::schema()->hasTable('mod_bladecp_vibe_welcome_queue')) {
                foreach (Capsule::table('mod_bladecp_vibe_welcome_queue')->where('status', 'queued')->get() as $row) {
                    $count++;
                    $at = trim((string) ($row->enqueued_at ?? ''));
                    if ($at !== '' && ($oldest === null || strcmp($at, $oldest) < 0)) {
                        $oldest = $at;
                    }
                }
            }
            foreach (['mod_bladecp_federation_sessions', 'mod_bladecp_whmcs_support_sessions'] as $table) {
                if (Capsule::schema()->hasTable($table)) {
                    foreach (Capsule::table($table)->where('revoked', 1)->where('delivered', 0)->get() as $row) {
                        if ((int) ($row->expires_at ?? 0) > time()) {
                            $count++;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Pending work is informative only.
        }
        return ['count' => $count, 'oldest' => $oldest];
    }

    private static function flash(): string
    {
        $done = (string) ($_GET['cron'] ?? '');
        $error = (string) ($_GET['cron_error'] ?? '');
        if (in_array($done, ['installed', 'already_installed', 'removed', 'not_installed', 'alerts_saved'], true)) {
            return '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check') . '<div>' . Layout::escape(I18n::t('diagnostics.cron.flash_' . $done)) . '</div></div>';
        }
        if ($error !== '') {
            $key = in_array($error, ['proc_open', 'crontab', 'read_failed', 'write_failed', 'verify_failed'], true) ? $error : 'failed';
            return '<div class="bcp-flash bcp-flash-no"><span class="bcp-pf-x">!</span><div>' . Layout::escape(I18n::t('diagnostics.cron.error_' . $key)) . '</div></div>';
        }
        return '';
    }

    private static function time(int $timestamp): string
    {
        return date('Y-m-d H:i', $timestamp);
    }

    private static function log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // The admin action result is shown even without the log.
        }
    }
}
