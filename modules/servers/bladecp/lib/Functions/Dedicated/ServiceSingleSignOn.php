<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

use WHMCS\Module\Server\Bladecp\Helpers\ClientText;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Dedicated services do not provide SSO. */
final class ServiceSingleSignOn
{
    public static function run(array $params): array
    {
        return [
            'success' => false,
            'errorMsg' => ClientText::get('sso_dedicated_unavailable', [], null, $params),
        ];
    }
}
