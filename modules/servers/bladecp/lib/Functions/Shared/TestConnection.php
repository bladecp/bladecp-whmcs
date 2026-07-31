<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Shared;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Tests the configured BladeCP API connection. */
final class TestConnection
{
    public static function run(array $params): array
    {
        try {
            $client = Client::fromParams($params);
            $me = $client->get('/me');
            Logger::logCall('TestConnection', [], $me);
            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
