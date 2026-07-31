<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\SuspendAccount as StackSuspendAccount;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates suspension to the shared handler. */
final class SuspendAccount
{
    public static function run(array $params): string
    {
        return StackSuspendAccount::run($params);
    }
}
