<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\ChangePackage as StackChangePackage;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates plan and configurable-option updates to the shared handler. */
final class ChangePackage
{
    public static function run(array $params): string
    {
        return StackChangePackage::run($params);
    }
}
