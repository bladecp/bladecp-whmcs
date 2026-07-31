<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Handles eligible Dedicated service suspension. */
final class SuspendAccount
{
    public static function run(array $params): string
    {
        if (self::awaitsManualDelivery($params)) {
            return 'BladeCP: este servidor ainda aguarda entrega manual e não pode ser suspenso pelo WHMCS.';
        }
        return \WHMCS\Module\Server\Bladecp\Functions\Stack\SuspendAccount::run($params);
    }

    private static function awaitsManualDelivery(array $params): bool
    {
        return strtolower(trim((string) ServiceProperties::get(
            $params,
            'BladeCP Provision Status',
            ''
        ))) === 'pending_manual';
    }
}
