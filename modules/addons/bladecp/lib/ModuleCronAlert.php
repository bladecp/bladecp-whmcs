<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Tells WHMCS administrators, through a WHMCS system notification, that the
 * module cron stopped. It runs from the native WHMCS cron, so it does not
 * depend on the cron it watches. A cron that never ran is shown in the
 * Diagnostics tab instead: only a cron that stopped sends email. A reminder
 * goes at most once a day, and a failed send waits 30 minutes.
 */
final class ModuleCronAlert
{
    private const ENABLED = 'module_cron_alerts';
    private const STATE = 'module_cron_alert_state';
    private const REMINDER_SECONDS = 86400;
    private const RETRY_SECONDS = 1800;

    public static function enabled(): bool
    {
        try {
            $value = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', self::ENABLED)->value('value');
            return !is_string($value) || trim($value) !== '0';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Called only after the native admin permission and CSRF checks. */
    public static function save(bool $enabled): bool
    {
        return self::write(self::ENABLED, $enabled ? '1' : '0');
    }

    /**
     * @param (callable(string,array<string,mixed>):array<string,mixed>)|null $localApi
     * @return 'disabled'|'idle'|'deferred'|'waiting'|'alerted'|'reminded'|'failed'
     */
    public static function run(?callable $localApi = null, ?\DateTimeImmutable $now = null): string
    {
        if (!self::enabled()) {
            return 'disabled';
        }
        $now ??= new \DateTimeImmutable('now');
        $heartbeat = (int) (ModuleCron::status()['heartbeatAt'] ?? 0);
        $state = self::state();
        if ($heartbeat <= 0 || $now->getTimestamp() - $heartbeat <= ModuleCron::QUEUE_STALE_SECONDS) {
            if ($state !== []) {
                self::write(self::STATE, '');
            }
            return 'idle';
        }
        if ((int) ($state['retryAfter'] ?? 0) > $now->getTimestamp()) {
            return 'deferred';
        }
        $sentAt = (int) ($state['sentAt'] ?? 0);
        if ($sentAt > 0 && $now->getTimestamp() - $sentAt < self::REMINDER_SECONDS) {
            return 'waiting';
        }
        $localApi ??= static fn (string $command, array $values): array => function_exists('localAPI') ? (array) localAPI($command, $values) : [];
        try {
            $result = $localApi('SendAdminEmail', [
                'customsubject' => self::text('diagnostics.cron.alert_subject'),
                'custommessage' => self::message($heartbeat),
                'type' => 'system',
            ]);
            if (($result['result'] ?? null) !== 'success') {
                throw new \RuntimeException('WHMCS did not confirm the admin notification.');
            }
        } catch (\Throwable $error) {
            self::write(self::STATE, (string) json_encode(['sentAt' => $sentAt, 'retryAfter' => $now->getTimestamp() + self::RETRY_SECONDS]));
            self::log('BladeCP module cron: stopped-cron alert not sent; retry in 30 minutes; cause=' . (new \ReflectionClass($error))->getShortName());
            return 'failed';
        }
        self::write(self::STATE, (string) json_encode(['sentAt' => $now->getTimestamp(), 'retryAfter' => 0]));
        self::log('BladeCP module cron: stopped-cron alert sent to administrators.');
        return $sentAt > 0 ? 'reminded' : 'alerted';
    }

    private static function message(int $heartbeat): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return '<p>' . $escape(self::text('diagnostics.cron.alert_body', ['time' => date('Y-m-d H:i', $heartbeat)])) . '</p>'
            . '<p>' . $escape(self::text('diagnostics.cron.command_label')) . '</p>'
            . '<p><code>' . $escape(ModuleCron::command()) . '</code></p>';
    }

    /** @param array<string,scalar> $replace */
    private static function text(string $key, array $replace = []): string
    {
        $previous = I18n::locale();
        $language = $GLOBALS['CONFIG']['Language'] ?? 'english';
        I18n::boot(['bladecp_locale' => is_string($language) ? $language : 'english']);
        try {
            return I18n::t($key, $replace);
        } finally {
            // The notice is written in the system language without changing the current one.
            I18n::boot(['bladecp_locale' => $previous]);
        }
    }

    /** @return array<string,int> */
    private static function state(): array
    {
        try {
            $value = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', self::STATE)->value('value');
            $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : null;
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private static function write(string $setting, string $value): bool
    {
        try {
            $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
            if ($query->exists()) {
                $query->update(['value' => $value]);
            } else {
                Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $value]);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // Observability never stops the native cron.
        }
    }
}
