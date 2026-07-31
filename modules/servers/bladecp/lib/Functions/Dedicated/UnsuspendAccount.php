<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Handles eligible Dedicated service reactivation. */
final class UnsuspendAccount
{
    public static function run(array $params): string
    {
        if (strtolower(trim((string) ServiceProperties::get(
            $params,
            'BladeCP Provision Status',
            ''
        ))) === 'pending_manual') {
            return 'BladeCP: este servidor ainda aguarda entrega manual e não pode ser reativado pelo WHMCS.';
        }
        return \WHMCS\Module\Server\Bladecp\Functions\Stack\UnsuspendAccount::run($params);
    }
}
