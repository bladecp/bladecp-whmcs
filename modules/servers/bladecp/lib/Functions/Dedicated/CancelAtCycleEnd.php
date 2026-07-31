<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates end-of-cycle cancellation to the shared handler. */
final class CancelAtCycleEnd
{
    public static function run(array $params): string
    {
        return \WHMCS\Module\Server\Bladecp\Functions\Stack\CancelAtCycleEnd::run($params);
    }
}
