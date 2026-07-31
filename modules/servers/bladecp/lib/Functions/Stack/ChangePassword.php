<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Reports that BladeCP services do not use a per-service password. */
final class ChangePassword
{
    public static function run(array $params): string
    {
        return 'BladeCP services have no per-service password — access '
            . 'is through your BladeCP account login. Sign in to the '
            . 'BladeCP Panel to manage your service and rotate any '
            . 'database/Redis credentials there.';
    }
}
