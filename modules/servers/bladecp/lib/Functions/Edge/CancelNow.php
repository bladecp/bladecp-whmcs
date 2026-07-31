<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\CancelNow as StackCancelNow;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates immediate cancellation to the shared handler. */
final class CancelNow
{
    public static function run(array $params): string
    {
        return StackCancelNow::run($params);
    }
}
