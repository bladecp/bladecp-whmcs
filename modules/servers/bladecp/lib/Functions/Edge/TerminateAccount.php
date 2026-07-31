<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\TerminateAccount as StackTerminateAccount;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates termination to the shared handler. */
final class TerminateAccount
{
    public static function run(array $params): string
    {
        return StackTerminateAccount::run($params);
    }
}
