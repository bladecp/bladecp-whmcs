<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Maps WHMCS immediate cancellation to the terminal Vibe lifecycle action. */
final class CancelNow
{
    public static function run(array $params): string
    {
        return TerminateAccount::run($params);
    }
}
