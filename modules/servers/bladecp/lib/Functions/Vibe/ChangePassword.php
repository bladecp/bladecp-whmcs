<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Helpers\ClientText;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Vibe authentication is account-based and has no per-service password. */
final class ChangePassword
{
    public static function run(array $params): string
    {
        return ClientText::get('vibe_no_password', [], null, $params);
    }
}
