<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;
use WHMCS\Module\Server\Bladecp\Ui\ClientPanel;
use WHMCS\Module\Server\Bladecp\Helpers\ClientLocale;
use WHMCS\Module\Server\Bladecp\Helpers\ClientText;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Renders Vibe using the shared service panel without exposing AI configuration. */
final class ClientArea
{
    public static function run(array $params): array
    {
        $accountId = self::accountId($params);
        $status = self::status($params, $accountId);
        $identityActivationUrl = self::identityActivationUrl($params);
        $html = ClientPanel::render([
            'productType' => 'vibe',
            'status' => $status,
            'accountId' => $accountId ?? '',
            'plan' => (string) ($params['productname'] ?? trim('Vibe ' . ucfirst(VibePlanTier::fromParams($params) ?? ''))),
            'serviceId' => self::canonicalServiceId($params) ?? 0,
        ], ClientLocale::resolve($params, is_array($_SESSION ?? null) ? $_SESSION : []));
        if ($identityActivationUrl !== null) {
            $locale = ClientLocale::resolve($params, is_array($_SESSION ?? null) ? $_SESSION : []);
            $html .= '<p>' . htmlspecialchars(ClientText::get('vibe_pro_activation_text', [], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p><p><a class="btn btn-primary" href="' . $identityActivationUrl . '">'
                . htmlspecialchars(ClientText::get('vibe_pro_activation_button', [], $locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
        }
        $vars = [
            'bladecpHtml' => $html,
            'status' => $status,
            'primaryDomain' => $accountId ?? '',
            'vibeAccountId' => $accountId ?? '',
            'vibeSsoUrl' => self::ssoUrl($params),
            'vibePanelHasAction' => true,
        ];

        return [
            'templatefile' => 'clientarea',
            'vars' => $vars,
            'templateVariables' => $vars,
        ];
    }

    private static function accountId(array $params): ?string
    {
        $accountId = ServiceProperties::get($params, 'BladeCP Vibe Account ID');
        $accountId = is_string($accountId) ? trim($accountId) : '';

        return $accountId === '' ? null : $accountId;
    }

    private static function status(array $params, ?string $accountId): string
    {
        $fallback = trim((string) ($params['status'] ?? ''));
        if ($accountId === null) {
            return $fallback;
        }

        try {
            $response = Client::fromParams($params)->get(
                '/vibe/accounts/' . rawurlencode($accountId)
            );
            $status = $response['status'] ?? null;
            if (is_string($status) && trim($status) !== '') {
                return trim($status);
            }
        } catch (\Throwable) {
            // Client area remains available if the API is temporarily unavailable.
        }

        return $fallback;
    }

    /**
     * Builds the official WHMCS SSO route only from the service ID supplied by
     * the server module runtime. Client-controlled request parameters are not
     * consulted here.
     */
    private static function ssoUrl(array $params): ?string
    {
        $rawServiceId = $params['serviceid'] ?? null;
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

        return 'clientarea.php?action=productdetails&id='
            . $serviceId
            . '&dosinglesignon=1';
    }

    /**
     * Exposes the activation entry point only for a locally pending Vibe Pro
     * service. The local state intentionally controls this recovery path: the
     * remote account state is enriched only for display and can be ahead of a
     * WHMCS status update. The route also permits a previously linked service
     * to resume final activation steps after a local interruption. The service
     * ID is always obtained from the server module runtime, never from a
     * client-controlled query parameter.
     */
    private static function identityActivationUrl(array $params): ?string
    {
        $localStatus = strtolower(trim((string) ($params['status'] ?? '')));
        if ($localStatus !== 'pending'
            || VibePlanTier::fromParams($params) !== VibePlanTier::PRO
        ) {
            return null;
        }

        $serviceId = self::canonicalServiceId($params);
        if ($serviceId === null) {
            return null;
        }

        return 'index.php?m=bladecp&amp;action=vibe-service-identity&amp;serviceid='
            . $serviceId;
    }

    private static function canonicalServiceId(array $params): ?int
    {
        $rawServiceId = $params['serviceid'] ?? null;
        if (is_int($rawServiceId)) {
            return $rawServiceId > 0 ? $rawServiceId : null;
        }

        if (!is_string($rawServiceId)
            || preg_match('/^[1-9][0-9]*$/D', $rawServiceId) !== 1
        ) {
            return null;
        }

        $serviceId = filter_var($rawServiceId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $serviceId !== false && hash_equals((string) $serviceId, $rawServiceId)
            ? $serviceId
            : null;
    }

}
