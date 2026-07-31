<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

use WHMCS\Module\Server\Bladecp\Functions\Stack\ServiceSingleSignOn as StackServiceSingleSignOn;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Delegates SSO link creation to the shared handler. */
final class ServiceSingleSignOn
{
    public static function run(array $params): array
    {
        return StackServiceSingleSignOn::run($params);
    }
}
