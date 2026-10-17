<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Functions\Vibe\IdentityHandoff as ServerIdentityHandoff;
use WHMCS\Module\Server\Bladecp\Ui\Brand;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Authenticated identity-link route for a specific paid Vibe Pro service. */
final class VibeServiceIdentityClientArea
{
    private const SESSION_KEY = 'bladecp_vibe_service_identity_context';

    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars): array
    {
        $post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $decision = IdentityHandoff::currentIdentityDecision();
        $identity = $decision['identity'];
        $clientId = is_array($identity) ? (int) ($identity['clientId'] ?? 0) : 0;
        $userId = is_array($identity) ? (int) ($identity['userId'] ?? 0) : 0;
        $activation = null;
        $identityRedirect = '';
        $isCallback = !$post && (string) ($_GET['action'] ?? '') === 'vibe-identity-callback';

        if ($isCallback) {
            self::protectCallbackResponse();
            $pending = $_SESSION[self::SESSION_KEY] ?? null;
            unset($_SESSION[self::SESSION_KEY]);
            if (is_array($identity) && is_array($pending)
                && ($pending['route'] ?? '') === 'vibe-service-identity'
                && ($pending['tier'] ?? '') === 'pro'
                && (int) ($pending['userId'] ?? 0) === $userId
                && (int) ($pending['clientId'] ?? 0) === $clientId
                && (int) ($pending['expiresAt'] ?? 0) >= time()) {
                $serviceId = self::positiveId($pending['serviceId'] ?? null);
                $prepared = $serviceId === null ? null : VibeServiceIdentityActivation::identityContextForService($serviceId, $clientId);
                if ($prepared !== null) {
                    $handoff = ServerIdentityHandoff::complete($prepared['client'], $pending, $_GET);
                    if (($handoff['status'] ?? '') === 'ready') {
                        $activation = VibeServiceIdentityActivation::finalizeIdentityExchange($serviceId, $clientId, $handoff);
                    }
                }
            }
            $activation ??= self::error(ClientCopy::text('client-flows', 'try_again'));
        }

        if ($post && (string) ($_POST['bladecp_action'] ?? '') === 'vibe_service_identity_start') {
            $serviceId = self::positiveId($_POST['serviceid'] ?? null);
            if (!is_array($identity) || $clientId <= 0 || $userId <= 0 || !self::validToken() || $serviceId === null) {
                $activation = self::error(ClientCopy::text('client-flows', 'activation_invalid'));
            } else {
                $prepared = VibeServiceIdentityActivation::identityContextForService($serviceId, $clientId);
                if ($prepared === null) {
                    // If the identity exchange already persisted successfully
                    // but AcceptOrder was interrupted, resume only that exact
                    // paid pending service. It never opens another handoff.
                    if (VibeServiceIdentityActivation::linkedPendingContextForService($serviceId, $clientId) !== null) {
                        $activation = VibeServiceIdentityActivation::resumeProvisioning($serviceId, $clientId);
                    } else {
                        $activation = self::error(ClientCopy::text('client-flows', 'service_activation_unavailable'));
                    }
                } else {
                    $context = [
                        'userId' => $userId,
                        'clientId' => $clientId,
                        'serviceId' => $serviceId,
                        'partnerPlanUuid' => $prepared['planUuid'],
                        'partnerAuthAt' => gmdate('c'),
                    ];
                    $federated = FederationFlow::serviceEnabled($serviceId);
                    $handoff = $federated ? FederationFlow::begin($serviceId, $identity, 'service') : ServerIdentityHandoff::begin($prepared['client'], $context);
                    if (($handoff['status'] ?? '') === 'ready') {
                        $activation = VibeServiceIdentityActivation::finalizeIdentityExchange($serviceId, $clientId, $handoff);
                    } elseif (($handoff['status'] ?? '') === 'link_required' && is_string($handoff['redirectTo'] ?? null)) {
                        if (!$federated) { $_SESSION[self::SESSION_KEY] = $context + [
                            'route' => 'vibe-service-identity',
                            'tier' => 'pro',
                            'expiresAt' => time() + 600,
                        ]; }
                        $identityRedirect = $handoff['redirectTo'];
                    } else {
                        $activation = self::error(ClientCopy::text('client-flows', 'try_again'));
                    }
                }
            }
        }

        $serviceId = self::positiveId($_GET['serviceid'] ?? $_GET['service'] ?? null) ?? 0;
        return [
            'pagetitle' => ClientCopy::text('client-flows', 'title_activate_pro'),
            'breadcrumb' => ['index.php?m=bladecp&action=vibe-service-identity' => 'BladeCP Vibe'],
            'templatefile' => 'vibe-service-identity',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'activationError' => trim((string) ($activation['error'] ?? '')),
                'identityError' => self::identityError((string) ($decision['status'] ?? '')),
                'identityRedirect' => $identityRedirect,
                'redirectTo' => trim((string) ($activation['redirectTo'] ?? '')),
                'serviceId' => $serviceId,
                'stripIdentityCallbackUrl' => $isCallback,
                'partnerBrandLogoHtml' => Brand::logo(32),
            ],
        ];
    }

    private static function validToken(): bool
    {
        if (!function_exists('check_token')) {
            return false;
        }
        try {
            // A successful native CSRF verification may return void.
            return check_token('WHMCS.default') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function protectCallbackResponse(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }

    private static function identityError(string $status): string
    {
        return match ($status) {
            'ready' => '',
            'masquerading_admin' => ClientCopy::text('client-flows', 'activation_admin_blocked'),
            'not_owner' => ClientCopy::text('client-flows', 'activation_owner_only'),
            default => ClientCopy::text('client-flows', 'activation_sign_in'),
        };
    }

    /** @return array{ok:false,error:string} */
    private static function error(string $message): array
    {
        return ['ok' => false, 'error' => $message];
    }
}
