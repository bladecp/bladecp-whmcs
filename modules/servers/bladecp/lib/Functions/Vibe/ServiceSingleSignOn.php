<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibeSsoDestination;
use WHMCS\Module\Server\Bladecp\Helpers\ClientText;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Creates a short-lived, single-use Vibe client SSO link. */
final class ServiceSingleSignOn
{
    public static function run(array $params): array
    {
        $service = self::activeService($params['serviceid'] ?? null);
        if ($service === null) {
            return [
                'success' => false,
                'errorMsg' => ClientText::get('sso_vibe_inactive', [], null, $params),
            ];
        }

        $serviceId = $service['id'];
        try {
            $serverId = $service['server'];
            if (class_exists(\WHMCS\Module\Addon\Bladecp\WhmcsSupportFlow::class)
                && \WHMCS\Module\Addon\Bladecp\WhmcsSupportFlow::isAdminSession()) {
                // The module-command request must not own pending callback state.
                // Start and finish in the public controller's writable session.
                $callback = IdentityHandoff::callbackUrl();
                if ($callback === null) { return ['success' => false, 'errorMsg' => ClientText::get('sso_admin_unavailable', [], null, $params)]; }
                $url = str_replace('action=vibe-identity-callback', 'action=vibe-admin-support', $callback) . '&serviceid=' . $serviceId;
                $launchToken = self::launchToken($params['launchToken'] ?? null);
                if ($launchToken !== null) { $url .= '&launchToken=' . rawurlencode($launchToken); }
                return ['success' => true, 'redirectTo' => $url];
            }

            if ((class_exists(\WHMCS\Module\Addon\Bladecp\IdentityHandoff::class)
                    && \WHMCS\Module\Addon\Bladecp\IdentityHandoff::currentMasqueradedIdentity() !== null)
                || \WHMCS\Module\Server\Bladecp\Helpers\FederationConfig::enabled($serverId)) {
                $callback = IdentityHandoff::callbackUrl();
                if ($callback === null) { return ['success' => false, 'errorMsg' => ClientText::get('sso_unavailable', [], null, $params)]; }
                return ['success' => true, 'redirectTo' => str_replace('action=vibe-identity-callback', 'action=vibe-federation', $callback) . '&serviceid=' . $serviceId];
            }
        } catch (\Throwable) { return ['success' => false, 'errorMsg' => ClientText::get('sso_unavailable', [], null, $params)]; }
        $accountId = self::accountId($params);
        if ($accountId === null) {
            return [
                'success' => false,
                'errorMsg' => ClientText::get('sso_vibe_account_missing', [], null, $params),
            ];
        }

        try {
            $client = Client::fromParams($params);
            $launchToken = self::launchToken($params['launchToken'] ?? null);
            $response = $client->post(
                '/vibe/accounts/' . rawurlencode($accountId) . '/sso',
                $launchToken === null ? [] : ['launchToken' => $launchToken],
                ['Idempotency-Key' => self::issuanceKey($params['serviceid'] ?? '0')]
            );
            Logger::logCall('VibeServiceSingleSignOn', ['accountId' => $accountId], $response);

            $url = $response['url'] ?? null;
            $expiresAt = trim((string) ($response['expiresAt'] ?? ''));
            $expires = $expiresAt === '' ? false : strtotime($expiresAt);
            if (!VibeSsoDestination::isAllowed($url, $client->baseUrl())
                || $expires === false || $expires <= time()) {
                return [
                    'success' => false,
                    'errorMsg' => ClientText::get('sso_vibe_link_invalid', [], null, $params),
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

    /**
     * Reads the authoritative WHMCS service state before any SSO redirect or
     * token issuance. The client-area controller owns user authorization;
     * this module gate owns the service lifecycle state.
     *
     * @return array{id:int,server:int}|null
     */
    private static function activeService(mixed $rawServiceId): ?array
    {
        if (is_int($rawServiceId)) {
            $serviceId = $rawServiceId;
        } elseif (is_string($rawServiceId)
            && preg_match('/^[1-9][0-9]*$/D', $rawServiceId) === 1
        ) {
            $serviceId = filter_var($rawServiceId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($serviceId === false || !hash_equals((string) $serviceId, $rawServiceId)) {
                return null;
            }
        } else {
            return null;
        }

        if ($serviceId <= 0) {
            return null;
        }

        try {
            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)
                ->first();
            if (!is_object($service)
                || (int) ($service->id ?? 0) !== $serviceId
                || !hash_equals('Active', (string) ($service->domainstatus ?? ''))
            ) {
                return null;
            }

            return ['id' => $serviceId, 'server' => (int) ($service->server ?? 0)];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function launchToken(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private static function accountId(array $params): ?string
    {
        $accountId = ServiceProperties::get($params, 'BladeCP Vibe Account ID');
        $accountId = is_string($accountId) ? trim($accountId) : '';

        return $accountId === '' ? null : $accountId;
    }

    /**
     * An SSO URL is single-use and short-lived. A stable key per service would
     * replay an already consumed or expired URL on every later access.
     */
    private static function issuanceKey(mixed $serviceId): string
    {
        $service = filter_var($serviceId, FILTER_VALIDATE_INT) !== false
            && (int) $serviceId > 0
            ? (string) (int) $serviceId
            : '0';

        return 'whmcs-' . $service . '-vibe-sso-' . bin2hex(random_bytes(12));
    }
}
