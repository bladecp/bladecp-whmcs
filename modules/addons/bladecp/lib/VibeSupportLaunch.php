<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Config\Environments;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Administrative entry to an existing Vibe service.
 *
 * This deliberately does not inspect CurrentUser or customer ownership. The
 * WHMCS addon ACL admits an administrator to this surface, the server record
 * attests the installation/service, and BladeCP Auth later identifies the
 * real operator before the API grants any support destination.
 */
final class VibeSupportLaunch
{
    /** @return array{serviceId:int,serverId:int,accountId:string,bindingVersion:int}|null */
    public static function contextForService(mixed $rawServiceId): ?array
    {
        $serviceId = self::positiveId($rawServiceId);
        if ($serviceId === null || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $serverId = self::positiveId($service->server ?? null);
            $productId = self::positiveId($service->packageid ?? null);
            if ($serverId === null || $productId === null) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $productId)->first();
            if ($product === null
                || strtolower(trim((string) ($product->servertype ?? ''))) !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $status = strtolower(trim((string) ($service->domainstatus ?? '')));
            if (!in_array($status, ['active', 'suspended'], true)) {
                return null;
            }
            $accountId = self::customField($serviceId, $productId, 'BladeCP Vibe Account ID');
            $externalServiceId = self::customField($serviceId, $productId, 'BladeCP External Service ID');
            $bindingVersion = filter_var(
                self::customField($serviceId, $productId, 'BladeCP Identity Binding Version'),
                FILTER_VALIDATE_INT
            );
            if ($accountId === null || $externalServiceId === null || !hash_equals((string) $serviceId, $externalServiceId)
                || $bindingVersion === false || (int) $bindingVersion <= 0) {
                return null;
            }
            return ['serviceId' => $serviceId, 'serverId' => $serverId, 'accountId' => $accountId, 'bindingVersion' => (int) $bindingVersion];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{ok:bool,authorizeUrl?:string,error?:string} */
    public static function issue(mixed $rawServiceId, ?string $launchToken = null): array
    {
        $context = self::contextForService($rawServiceId);
        if ($context === null) {
            return ['ok' => false, 'error' => ClientCopy::text('client-flows', 'support_service_unavailable')];
        }
        try {
            $client = ServerConfig::clientForServerId($context['serverId']);
            if ($client === null) {
                return ['ok' => false, 'error' => ClientCopy::text('client-flows', 'support_installation_unavailable')];
            }
            $intent = $client->issueVibeSupportIntent(
                $context['serviceId'], $context['accountId'], $context['bindingVersion'], $launchToken
            );
            $url = $intent['authorizeUrl'];
            if (!self::isAuthorizeUrl($url, $client->baseUrl())) {
                return ['ok' => false, 'error' => ClientCopy::text('client-flows', 'support_access_unprepared')];
            }
            return ['ok' => true, 'authorizeUrl' => $url];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => ClientCopy::text('client-flows', 'support_access_unprepared')];
        }
    }

    public static function render(string $base, mixed $rawServiceId, string $error = ''): string
    {
        $context = self::contextForService($rawServiceId);
        if ($context === null) {
            return $error === '' ? '' : '<p class="bcp-row-v">' . self::escape($error) . '</p>';
        }
        $serviceId = (string) $context['serviceId'];
        $button = '<button class="btn btn-primary" type="submit">Abrir Vibe para atendimento</button>';
        return '<section class="bcp-support-launch" aria-label="Atendimento BladeCP Vibe">'
            . ($error !== ''
                ? '<p class="bcp-row-v" role="alert">' . self::escape($error) . '</p>'
                : '<p role="status">Abrindo o Vibe pela sua conta BladeCP…</p>')
            . '<form id="bladecp-vibe-support-launch" method="post" action="' . self::escape($base) . '">'
            . self::token()
            . '<input type="hidden" name="action" value="vibe-support-launch">'
            . '<input type="hidden" name="serviceid" value="' . self::escape($serviceId) . '">'
            . ($error !== '' ? $button : '<noscript>' . $button . '</noscript>')
            . '</form>'
            . ($error !== '' ? '' : '<script>document.getElementById("bladecp-vibe-support-launch").submit();</script>')
            . '</section>';
    }

    public static function redirect(string $url): string
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
        return '<meta name="referrer" content="no-referrer"><script>window.location.replace('
            . json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ');</script><noscript><a rel="noreferrer" href="' . self::escape($url)
            . '">' . self::escape(ClientCopy::text('client-flows', 'continue_to_bladecp')) . '</a></noscript>';
    }

    private static function isAuthorizeUrl(string $url, string $apiBaseUrl): bool
    {
        $parts = parse_url($url);
        $api = parse_url($apiBaseUrl);
        if (!is_array($parts) || !is_array($api)
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['fragment']) || ($parts['path'] ?? '') !== '/support/authorize') {
            return false;
        }
        $expectedHost = Environments::byApiHost(strtolower((string) ($api['host'] ?? '')))['supportHost'] ?? null;
        if ($expectedHost === null || !hash_equals($expectedHost, strtolower((string) ($parts['host'] ?? '')))) {
            return false;
        }
        $query = (string) ($parts['query'] ?? '');
        if (preg_match('/^intent=([A-Za-z0-9_-]{16,512})$/D', $query, $match) !== 1) {
            return false;
        }
        return rawurldecode($match[1]) === $match[1];
    }

    private static function customField(int $serviceId, int $productId, string $name): ?string
    {
        $field = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $productId)->where('fieldname', $name)->first();
        $fieldId = self::positiveId($field->id ?? null);
        if ($fieldId === null) {
            return null;
        }
        $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)->where('relid', $serviceId)->first();
        $value = trim((string) ($row->value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }

    private static function token(): string
    {
        return function_exists('generate_token') ? (string) generate_token('WHMCS.admin.default') : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
