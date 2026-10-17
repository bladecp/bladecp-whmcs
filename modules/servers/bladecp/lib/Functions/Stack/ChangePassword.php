<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Helpers\ClientText;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Reports that BladeCP services do not use a per-service password. */
final class ChangePassword
{
    public static function run(array $params): string
    {
        return ClientText::get('password_not_used', [], null, $params);
    }
}
