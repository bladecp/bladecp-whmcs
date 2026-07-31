<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** `_TerminateAccount` → DELETE /customer/provisions/:uuid. */
final class TerminateAccount
{
    public static function run(array $params): string
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return 'BladeCP: provision UUID ausente.';
        }
        $client = Client::fromParams($params);
        $key = 'whmcs-' . ($params['serviceid'] ?? '0') . '-terminate';

        $response = $client->delete(
            "/provisions/{$uuid}",
            ['mode' => 'immediate'],
            ['Idempotency-Key' => $key]
        );

        Logger::logCall('TerminateAccount', ['uuid' => $uuid], $response);
        return 'success';
    }
}
