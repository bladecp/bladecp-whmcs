<?php

/**
 * BladeCP module cron. Run it every minute from the server crontab:
 *
 *   * * * * * php -q '/path/to/whmcs/modules/addons/bladecp/cron.php'
 *
 * It runs the module's light queues every minute and its daily work once a
 * day, each task under its own lock and time budget. The WHMCS cron hooks
 * only rescue this work when this cron has stopped.
 *
 * Command line only: a web request gets a 404 and runs nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

@set_time_limit(300);

require_once dirname(__DIR__, 3) . '/init.php';
require_once dirname(__DIR__, 2) . '/servers/bladecp/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerAddonAutoloaders(__DIR__, dirname(__DIR__, 2) . '/servers/bladecp');

$report = \WHMCS\Module\Addon\Bladecp\ModuleCron::run();
fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit((int) ($report['failed'] ?? 0) > 0 ? 1 : 0);
