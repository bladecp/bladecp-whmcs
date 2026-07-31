<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ParamsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Creates a short-lived SSO link for a provision. */
final class ServiceSingleSignOn
{
    public static function run(array $params): array
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return ['success' => false, 'errorMsg' => 'Provision UUID ausente.'];
        }
        try {
            $client = Client::fromParams($params);
            $response = $client->post("/provisions/{$uuid}/sso-link", [
                'ttl_seconds' => 300,
                'target' => 'panel',
            ]);
            Logger::logCall('ServiceSingleSignOn', ['uuid' => $uuid], $response);
            $url = $response['url'] ?? null;
            if (!ParamsMapper::isAbsoluteHttpsUrl($url)) {
                return [
                    'success' => false,
                    'errorMsg' => 'A API não retornou um link HTTPS válido para acesso ao painel.',
                ];
            }
            return [
                'success' => true,
                'redirectTo' => $url,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'errorMsg' => $e->getMessage()];
        }
    }
}
