<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * The Vibe lifecycle contract has no delayed-cancellation state. WHMCS
 * executes this callback only when its own billing cycle reaches the end,
 * so termination is the canonical matching lifecycle operation.
 */
final class CancelAtCycleEnd
{
    public static function run(array $params): string
    {
        return TerminateAccount::run($params);
    }
}
