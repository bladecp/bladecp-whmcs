<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Functions\Vibe\IdentityHandoff as ServerIdentityHandoff;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Ui\PartnerBrand;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Client Area bridge for the cart-free Vibe Free activation route. */
final class VibeFreeClientArea
{
    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars): array
    {
        $isVibeFreeRoute = self::isVibeFreeRoute();
        $startPresent = isset($_GET['start']);
        $capturedStart = false;
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' && $isVibeFreeRoute) {
            $capturedStart = \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::rememberLaunchTokenFromRequest();
            if ($startPresent) {
                self::protectLaunchStartResponse();
            }
        }
        // Before a Vibe service is resolved, use only the administrator's
        // configured server context. This is either the sole eligible server
        // discovered by WHMCS or an explicit, validated selection. An
        // ambiguous, missing or invalid context must still fall back to the
        // neutral BladeCP mark rather than showing another partner's brand.
        if (IdentityHandoff::currentMasqueradedIdentity() !== null && self::isVibeFreeRoute()) {
            if ($startPresent && !$capturedStart) {
                return VibeSupportClientArea::render(null, ClientCopy::text('client-flows', 'creation_request_invalid'));
            }
            return VibeSupportClientArea::render(null, '', $capturedStart);
        }
        $brandClient = self::initialBrandClient();
        $identityDecision = IdentityHandoff::currentIdentityDecision();
        $identity = $identityDecision['identity'];
        $clientId = is_array($identity) ? (int) ($identity['clientId'] ?? 0) : 0;
        $post = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
        $traceId = VibeFreeTrace::id($_POST['bladecp_trace'] ?? null);
        VibeFreeTrace::record('identity_gate', $traceId, [
            'identity_status' => $identityDecision['status'],
        ]);
        $sessionName = function_exists('session_name') ? session_name() : '';
        if ($clientId > 0) {
            VibeFreeTrace::record('route_enter', $traceId, [
                'method' => $post ? 'post' : 'get',
                'post_action_matches' => (string) ($_POST['bladecp_action'] ?? '') === 'vibe_free_identity_start',
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session_cookie_present' => $sessionName !== '' && isset($_COOKIE[$sessionName]),
                'session_uid_present' => isset($_SESSION) && is_array($_SESSION) && isset($_SESSION['uid']),
                'authenticated_client_present' => true,
                'vars_client_present' => false,
                'vars_csrf_present' => trim((string) ($vars['token'] ?? '')) !== '',
                'post_csrf_present' => trim((string) ($_POST['token'] ?? '')) !== '',
                'request_secure' => self::requestIsSecure(),
            ]);
        }
        $launchToken = \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::launchToken();
        if ($clientId <= 0 && !$post) {
            \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::rememberGuestIntent();
        } elseif ($clientId > 0 && $launchToken === null) {
            \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::consumeIntent();
        }
        $activation = null;
        $identityRedirect = '';
        $action = (string) ($_POST['bladecp_action'] ?? '');
        $isIdentityCallback = !$post && (string) ($_GET['action'] ?? '') === 'vibe-identity-callback';
        if ($isIdentityCallback) {
            self::protectIdentityCallbackResponse();
            $pending = $_SESSION['bladecp_vibe_identity_context'] ?? null;
            unset($_SESSION['bladecp_vibe_identity_context']);
            // Clear any later route attempt, but never use it for this
            // callback. The token attached to $pending is the only authority.
            self::consumeLaunchToken();
            if (is_array($identity) && is_array($pending)
                && (int) ($pending['userId'] ?? 0) === $identity['userId']
                && (int) ($pending['clientId'] ?? 0) === $identity['clientId']
                && (int) ($pending['expiresAt'] ?? 0) >= time()) {
                $serviceId = (int) ($pending['serviceId'] ?? 0);
                $prepared = VibeFreeActivation::identityContextForService($serviceId);
                if ($prepared !== null) {
                    $brandClient = $prepared['client'];
                    $handoff = ServerIdentityHandoff::complete($prepared['client'], $pending, $_GET);
                    if (($handoff['status'] ?? '') === 'ready') {
                        $activation = VibeFreeActivation::finalizeIdentityExchange(
                            $serviceId,
                            $handoff,
                            $traceId,
                            self::launchTokenFromIdentityContext($pending)
                        );
                    } else {
                        $activation = ['ok' => false, 'error' => ClientCopy::text('client-flows', 'try_again')];
                    }
                }
            }
            $activation ??= ['ok' => false, 'error' => ClientCopy::text('client-flows', 'try_again')];
        }
        if ($post && $action === 'vibe_free_identity_start') {
            $validToken = $clientId > 0 && self::validToken();
            if (!$validToken || !is_array($identity)) {
                $activation = ['ok' => false, 'error' => ClientCopy::text('client-flows', 'activation_invalid')];
            } else {
                $prepared = VibeFreeActivation::prepareForIdentity($clientId, $traceId);
                if (($prepared['ok'] ?? false) !== true || !isset($prepared['client'], $prepared['serviceId'], $prepared['planUuid'])) {
                    $activation = $prepared;
                } else {
                    $brandClient = $prepared['client'];
                    $context = [
                        'userId' => $identity['userId'], 'clientId' => $identity['clientId'],
                        'serviceId' => (int) $prepared['serviceId'], 'partnerPlanUuid' => (string) $prepared['planUuid'],
                        'partnerAuthAt' => gmdate('c'),
                    ];
                    $handoff = FederationFlow::serviceEnabled((int) $prepared['serviceId'])
                        ? FederationFlow::begin((int) $prepared['serviceId'], $identity, 'free', self::consumeLaunchToken())
                        : ServerIdentityHandoff::begin($prepared['client'], $context);
                    VibeFreeTrace::record('identity_intent_finish', $traceId, [
                        'outcome' => (string) ($handoff['status'] ?? 'error'),
                        'reason' => (string) ($handoff['reason'] ?? ''),
                        'contract_field' => (string) ($handoff['contractField'] ?? ''),
                    ]);
                    if (($handoff['status'] ?? '') === 'ready') {
                        $activation = VibeFreeActivation::finalizeIdentityExchange((int) $prepared['serviceId'], $handoff, $traceId, self::consumeLaunchToken());
                    } elseif (($handoff['status'] ?? '') === 'link_required' && is_string($handoff['redirectTo'] ?? null)) {
                        // Move the token into this exact PKCE context. A later
                        // browser request cannot replace it before callback.
                        if (!FederationFlow::serviceEnabled((int) $prepared['serviceId'])) {
                            $_SESSION['bladecp_vibe_identity_context'] = $context + [
                                'expiresAt' => time() + 600,
                                'launchToken' => self::consumeLaunchToken(),
                            ];
                        }
                        $identityRedirect = $handoff['redirectTo'];
                    } else {
                        $activation = ['ok' => false, 'error' => ClientCopy::text('client-flows', 'try_again')];
                    }
                }
            }
        }
        if ($post && $action === 'vibe_free_activate') {
            // The original direct activation is intentionally retired. It
            // inferred customer identity from the legacy WHMCS path; the only
            // supported creation route now has the explicit owner gate and
            // identity-link exchange above.
            $activation = ['ok' => false, 'error' => ClientCopy::text('client-flows', 'refresh_secure_activation')];
        }

        $identityError = self::identityError((string) $identityDecision['status']);
        $autoSubmit = !$post
            && $clientId > 0
            && $identityError === '';
        $brand = PartnerBrand::fromClient($brandClient);
        return [
            'pagetitle' => ClientCopy::text('client-flows', 'title_activate_free'),
            'breadcrumb' => ['index.php?m=bladecp&action=vibe-free' => 'BladeCP Vibe'],
            'templatefile' => 'vibe-free',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'activationError' => trim((string) ($activation['error'] ?? '')),
                'identityError' => $identityError,
                'identityRedirect' => $identityRedirect,
                'stripIdentityCallbackUrl' => $isIdentityCallback,
                'redirectTo' => trim((string) ($activation['redirectTo'] ?? '')),
                'traceId' => $traceId,
                'autoSubmit' => $autoSubmit,
                'stripLaunchStartUrl' => $isVibeFreeRoute && isset($_GET['start']),
                'partnerBrandLogoHtml' => PartnerBrand::logoHtml($brand),
            ],
        ];
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

    /**
     * Initial GET has no service context. AdminServerContext has already
     * verified that a configured server is either uniquely discovered or an
     * explicit selection from its current candidates.
     */
    private static function initialBrandClient(): ?Client
    {
        $context = AdminServerContext::resolve();
        if (!self::canResolveInitialBrand($context)) {
            return null;
        }
        $serverId = filter_var($context['serverId'] ?? null, FILTER_VALIDATE_INT);

        return $serverId === false ? null : ServerConfig::clientForServerId((int) $serverId);
    }

    /** @param array<string,mixed> $context */
    private static function canResolveInitialBrand(array $context): bool
    {
        return ($context['state'] ?? '') === 'configured'
            && filter_var($context['serverId'] ?? null, FILTER_VALIDATE_INT) !== false;
    }

    private static function validToken(): bool
    {
        if (!function_exists('check_token')) {
            return false;
        }
        try {
            // Supported WHMCS releases may confirm a valid token without a
            // return value. Only an explicit false result rejects the POST.
            return check_token('WHMCS.default') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The authorization code and state must never be cached or sent as a
     * referrer. The template also removes the query from browser history
     * before rendering customer-facing content.
     */
    private static function protectIdentityCallbackResponse(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
    }

    private static function protectLaunchStartResponse(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
    }

    private static function consumeLaunchToken(): ?string
    {
        return \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::consumeLaunchToken();
    }

    /** @param array<string,mixed> $context */
    private static function launchTokenFromIdentityContext(array $context): ?string
    {
        $token = $context['launchToken'] ?? null;
        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1
            ? $token
            : null;
    }

    private static function isVibeFreeRoute(): bool
    {
        return (string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'vibe-free';
    }

    private static function requestIsSecure(): bool
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
