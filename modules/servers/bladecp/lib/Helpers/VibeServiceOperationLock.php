<?php
declare(strict_types=1);
namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Serialize service lifecycle with the authenticated account exchange. */
final class VibeServiceOperationLock
{
    /** @return (\Closure():void)|null */
    public static function acquire(int $serviceId, int $timeout = 5): ?\Closure
    {
        if ($serviceId <= 0) { return null; }
        $timeout = max(0, min(5, $timeout));
        try {
            $connection = Capsule::connection();
            $driver = strtolower((string) $connection->getDriverName());
            $name = 'bladecp_vibe_service_' . $serviceId;
            if ($driver === 'mysql') {
                $rows = $connection->select('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
                $row = $rows[0] ?? null;
                if ((int) (is_object($row) ? ($row->acquired ?? 0) : ($row['acquired'] ?? 0)) !== 1) { return null; }
                $released = false;
                return static function () use ($connection, $name, &$released): void {
                    if ($released) { return; }
                    $released = true;
                    try { $connection->select('SELECT RELEASE_LOCK(?) AS released', [$name]); } catch (\Throwable) {}
                };
            }
            // SQLite is a disposable test runtime, never a production fallback.
            if ($driver !== 'sqlite' || PHP_SAPI !== 'cli') { return null; }
            $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.lock';
            $handle = @fopen($path, 'c');
            if ($handle === false) { return null; }
            @chmod($path, 0600);
            $deadline = microtime(true) + $timeout;
            do {
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    $released = false;
                    return static function () use ($handle, &$released): void {
                        if ($released) { return; }
                        $released = true;
                        @flock($handle, LOCK_UN);
                        @fclose($handle);
                    };
                }
                if ($timeout === 0) { break; }
                usleep(50000);
            } while (microtime(true) < $deadline);
            @fclose($handle);
        } catch (\Throwable) {}
        return null;
    }
}
