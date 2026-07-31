<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

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
            'errorMsg' => 'Este servidor não oferece login único pelo painel.',
        ];
    }
}
