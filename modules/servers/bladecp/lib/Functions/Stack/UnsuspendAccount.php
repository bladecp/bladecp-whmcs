<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** `_UnsuspendAccount` → POST /customer/provisions/:uuid/unsuspend. */
final class UnsuspendAccount
{
    public static function run(array $params): string
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return 'BladeCP: provision UUID ausente.';
        }
        $client = Client::fromParams($params);
        $key = 'whmcs-' . ($params['serviceid'] ?? '0') . '-unsuspend';

        $response = $client->post(
            "/provisions/{$uuid}/unsuspend",
            [],
            ['Idempotency-Key' => $key]
        );

        Logger::logCall('UnsuspendAccount', ['uuid' => $uuid], $response);
        return 'success';
    }
}
