<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingImport;
use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingSettings;
use WHMCS\Module\Addon\Bladecp\FastDeploy\Commercial;
use WHMCS\Module\Addon\Bladecp\FastDeploy\Expiry;
use WHMCS\Module\Server\Bladecp\Helpers\FederationLogout;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The module's own scheduler, run every minute by cron.php.
 *
 * The native WHMCS cron runs many tasks in sequence and calls its hooks only
 * at the end, so an unrelated failure there would stop this work for days.
 * Here every task has its own lock, its own time budget and its own error
 * record: one failing task never prevents the others.
 *
 * The native cron hooks only rescue this work when the module cron has
 * stopped: queues after 15 minutes without a heartbeat, the daily work after
 * 36 hours without a success. In normal operation they return at once.
 */
final class ModuleCron
{
    public const SOURCE_MODULE = 'module_cron';
    public const SOURCE_RESCUE = 'whmcs_cron_rescue';
    public const QUEUE_STALE_SECONDS = 900;
    public const DAILY_STALE_SECONDS = 129600;
    /** Local hour from which the daily work runs, once per day. */
    public const DAILY_HOUR = 3;
    public const MINUTE_TASKS = ['federation_logout', 'vibe_welcome', 'billing_import', 'commercial'];
    public const DAILY_TASK = 'daily';

    /** Seconds in which each task may start new work; a call already started completes. */
    private const BUDGET = ['federation_logout' => 15, 'vibe_welcome' => 20, 'billing_import' => 25, 'commercial' => 25, 'daily' => 240];
    private const RESCUE_BUDGET = 8;
    private const HEARTBEAT = 'module_cron_heartbeat';
    private const DAILY_DATE = 'module_cron_daily_date';
    private const TASK_PREFIX = 'module_cron_task_';

    /**
     * Entry point of cron.php.
     *
     * @param array<string,callable(float):mixed>|null $tasks replacement tasks, for tests
     * @return array{ran:bool,reason?:string,tasks?:array<string,array<string,mixed>>,failed?:int}
     */
    public static function run(?array $tasks = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        if (!self::addonActive()) {
            return ['ran' => false, 'reason' => 'addon_inactive'];
        }
        $tasks ??= self::tasks();
        // Written first, so a long task never makes the rescue start.
        self::write(self::HEARTBEAT, (string) $now->getTimestamp());
        $report = ['ran' => true, 'tasks' => [], 'failed' => 0];
        foreach (self::MINUTE_TASKS as $name) {
            if (isset($tasks[$name])) {
                $report['tasks'][$name] = self::runTask($name, $tasks[$name], self::BUDGET[$name], self::SOURCE_MODULE, $now);
            }
        }
        if (isset($tasks[self::DAILY_TASK]) && self::dailyDue($now)) {
            // Marked before running: a failing daily task retries tomorrow,
            // or through the native daily cron after 36 hours, never every minute.
            self::write(self::DAILY_DATE, $now->format('Y-m-d'));
            $report['tasks'][self::DAILY_TASK] = self::runTask(self::DAILY_TASK, $tasks[self::DAILY_TASK], self::BUDGET[self::DAILY_TASK], self::SOURCE_MODULE, $now);
        }
        foreach ($report['tasks'] as $result) {
            $report['failed'] += ($result['status'] ?? '') === 'error' ? 1 : 0;
        }
        return $report;
    }

    /**
     * AfterCronJob rescue: runs the minute tasks with a short budget only
     * when the module cron has not been seen for 15 minutes.
     *
     * @param array<string,callable(float):mixed>|null $tasks
     */
    public static function rescueQueues(?array $tasks = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $heartbeat = (int) self::read(self::HEARTBEAT);
        if ($heartbeat > 0 && $now->getTimestamp() - $heartbeat <= self::QUEUE_STALE_SECONDS) {
            return ['ran' => false, 'reason' => 'module_cron_alive'];
        }
        $tasks ??= self::tasks();
        $report = ['ran' => true, 'tasks' => []];
        foreach (self::MINUTE_TASKS as $name) {
            if (isset($tasks[$name])) {
                $report['tasks'][$name] = self::runTask($name, $tasks[$name], self::RESCUE_BUDGET, self::SOURCE_RESCUE, $now);
            }
        }
        return $report;
    }

    /**
     * DailyCronJob rescue: runs the daily work only when it has not
     * succeeded for 36 hours.
     *
     * @param (callable(float):mixed)|null $task
     */
    public static function rescueDaily(?callable $task = null, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $lastOk = (int) (self::taskState(self::DAILY_TASK)['lastOkAt'] ?? 0);
        if ($lastOk > 0 && $now->getTimestamp() - $lastOk <= self::DAILY_STALE_SECONDS) {
            return ['ran' => false, 'reason' => 'daily_recent'];
        }
        self::write(self::DAILY_DATE, $now->format('Y-m-d'));
        return ['ran' => true, 'tasks' => [self::DAILY_TASK => self::runTask(
            self::DAILY_TASK,
            $task ?? self::tasks()[self::DAILY_TASK],
            self::BUDGET[self::DAILY_TASK],
            self::SOURCE_RESCUE,
            $now
        )]];
    }

    /** @return array{heartbeatAt:int,dailyDate:string,tasks:array<string,array<string,mixed>>} */
    public static function status(): array
    {
        $tasks = [];
        foreach (array_merge(self::MINUTE_TASKS, [self::DAILY_TASK]) as $name) {
            $tasks[$name] = self::taskState($name);
        }
        return ['heartbeatAt' => (int) self::read(self::HEARTBEAT), 'dailyDate' => (string) self::read(self::DAILY_DATE), 'tasks' => $tasks];
    }

    /**
     * The command that runs this cron, with the PHP binary of this server
     * when it is known and the real path of cron.php in this WHMCS.
     */
    public static function command(?string $root = null, ?string $phpBinDir = null): string
    {
        $root ??= defined('ROOTDIR') ? (string) constant('ROOTDIR') : dirname(__DIR__, 4);
        $phpBinDir ??= PHP_BINDIR;
        $script = rtrim($root, '/') . '/modules/addons/bladecp/cron.php';
        $php = trim($phpBinDir) !== '' ? rtrim($phpBinDir, '/') . '/php' : 'php';
        return $php . " -q '" . str_replace("'", "'\\''", $script) . "'";
    }

    /** @return array<string,callable(float):mixed> */
    private static function tasks(): array
    {
        return [
            'federation_logout' => static function (float $deadline): array {
                FederationLogout::flush();
                return [];
            },
            'vibe_welcome' => static fn (float $deadline): array => VibeWelcomeQueue::flush(),
            'billing_import' => static function (float $deadline): array {
                if (BillingSettings::serverIds() === []) {
                    return ['servers' => 0];
                }
                $import = BillingImport::run($deadline);
                foreach (($import['servers'] ?? []) as $serverId => $counts) {
                    if (is_array($counts) && array_sum($counts) > 0) {
                        self::log('BladeCP migration billing: server_id=' . (int) $serverId
                            . '; linked=' . (int) ($counts['linked'] ?? 0) . '; claim=' . (int) ($counts['claim'] ?? 0)
                            . '; review=' . (int) ($counts['review'] ?? 0) . '; closed=' . (int) ($counts['closed'] ?? 0)
                            . '; retry=' . (int) ($counts['retry'] ?? 0) . ';');
                    }
                }
                return ['servers' => count($import['servers'] ?? [])];
            },
            'commercial' => static fn (float $deadline): array => Expiry::run(null, false, $deadline),
            self::DAILY_TASK => static fn (float $deadline): array => DailySync::run(),
        ];
    }

    /**
     * @param callable(float):mixed $task
     * @return array<string,mixed>
     */
    private static function runTask(string $name, callable $task, int $seconds, string $source, \DateTimeImmutable $now): array
    {
        // Never waits: a run that already holds the task covers this one.
        $release = Commercial::lock('cron:' . $name, 0);
        if ($release === null) {
            return ['status' => 'busy'];
        }
        $started = microtime(true);
        try {
            $result = $task($started + max(1, $seconds));
            $milliseconds = (int) round((microtime(true) - $started) * 1000);
            self::record($name, $source, $now, null, $milliseconds, is_array($result) ? $result : []);
            return ['status' => 'ok', 'ms' => $milliseconds] + (is_array($result) && $result !== [] ? ['result' => $result] : []);
        } catch (\Throwable $error) {
            $milliseconds = (int) round((microtime(true) - $started) * 1000);
            $cause = self::cause($error);
            self::record($name, $source, $now, $cause, $milliseconds);
            self::log('BladeCP module cron: task ' . $name . ' failed; source=' . $source . '; cause=' . $cause);
            return ['status' => 'error', 'ms' => $milliseconds, 'error' => $cause];
        } finally {
            $release();
        }
    }

    private static function dailyDue(\DateTimeImmutable $now): bool
    {
        return (int) $now->format('G') >= self::DAILY_HOUR && self::read(self::DAILY_DATE) !== $now->format('Y-m-d');
    }

    /** @param array<string,mixed> $result */
    private static function record(string $name, string $source, \DateTimeImmutable $now, ?string $error, int $milliseconds, array $result = []): void
    {
        $state = self::taskState($name);
        $state['lastRunAt'] = $now->getTimestamp();
        $state['lastSource'] = $source;
        $state['lastMs'] = $milliseconds;
        // What the task did, so a run without errors never hides a skip.
        $state['lastResult'] = array_slice(array_filter($result, static fn ($value): bool => is_int($value) || is_bool($value)), 0, 8, true);
        if ($error === null) {
            $state['lastOkAt'] = $now->getTimestamp();
        } else {
            $state['lastErrorAt'] = $now->getTimestamp();
            $state['lastError'] = $error;
        }
        try {
            self::write(self::TASK_PREFIX . $name, json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // The task result stands even if its record cannot be written.
        }
    }

    /** @return array<string,mixed> */
    private static function taskState(string $name): array
    {
        $value = self::read(self::TASK_PREFIX . $name);
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private static function cause(\Throwable $error): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $error->getMessage()));
        $message = Logger::redact($message);
        $message = (string) preg_replace('/[^\s@]+@[^\s@]+\.[^\s@]+/', '[email]', $message);
        return substr((new \ReflectionClass($error))->getShortName() . ($message === '' ? '' : ': ' . $message), 0, 240);
    }

    private static function addonActive(): bool
    {
        try {
            return class_exists(Capsule::class)
                && Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', 'version')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function read(string $setting): ?string
    {
        try {
            $value = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting)->value('value');
            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function write(string $setting, string $value): void
    {
        try {
            $query = Capsule::table('tbladdonmodules')->where('module', 'bladecp')->where('setting', $setting);
            if ($query->exists()) {
                $query->update(['value' => $value]);
            } else {
                Capsule::table('tbladdonmodules')->insert(['module' => 'bladecp', 'setting' => $setting, 'value' => $value]);
            }
        } catch (\Throwable) {
            // Scheduling state is best effort; the locks keep runs exclusive.
        }
    }

    private static function log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // Observability never stops a task.
        }
    }
}
