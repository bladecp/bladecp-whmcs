<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Suspends a provision by UUID. */
final class SuspendAccount
{
    public static function run(array $params): string
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return 'BladeCP: provision UUID ausente — serviço não foi criado pelo módulo?';
        }
        $client = Client::fromParams($params);
        $key = 'whmcs-' . ($params['serviceid'] ?? '0') . '-suspend';

        $response = $client->post(
            "/provisions/{$uuid}/suspend",
            ['reason' => 'admin-suspend'],
            ['Idempotency-Key' => $key]
        );

        Logger::logCall('SuspendAccount', ['uuid' => $uuid], $response);
        return 'success';
    }
}
