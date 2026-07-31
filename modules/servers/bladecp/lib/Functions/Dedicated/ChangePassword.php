<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Dedicated services do not use a per-service WHMCS password. */
final class ChangePassword
{
    public static function run(array $params): string
    {
        return \WHMCS\Module\Server\Bladecp\Functions\Stack\ChangePassword::run($params);
    }
}
