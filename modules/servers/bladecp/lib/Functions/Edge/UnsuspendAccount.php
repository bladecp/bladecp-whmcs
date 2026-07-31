<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\UnsuspendAccount as StackUnsuspendAccount;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates reactivation to the shared handler. */
final class UnsuspendAccount
{
    public static function run(array $params): string
    {
        return StackUnsuspendAccount::run($params);
    }
}
