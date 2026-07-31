<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\ChangePassword as StackChangePassword;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates the unsupported per-service password response. */
final class ChangePassword
{
    public static function run(array $params): string
    {
        return StackChangePassword::run($params);
    }
}
