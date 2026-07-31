<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\CancelAtCycleEnd as StackCancelAtCycleEnd;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates end-of-cycle cancellation to the shared handler. */
final class CancelAtCycleEnd
{
    public static function run(array $params): string
    {
        return StackCancelAtCycleEnd::run($params);
    }
}
