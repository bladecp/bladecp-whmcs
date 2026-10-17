<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** The automatic transition still requires a native owner and POST CSRF. */
final class FederationClientArea
{
    public static function render(array $vars): array
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0'); header('Referrer-Policy: no-referrer'); header('Pragma: no-cache');
        }
        if (($_GET['action'] ?? '') === 'vibe-admin-support') { return WhmcsSupportFlow::renderAdminEntry(); }
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if (WhmcsSupportFlow::handlesCallback($_GET)) {
            return WhmcsSupportFlow::renderCallback($_GET);
        }
        if (!$post && IdentityHandoff::currentMasqueradedIdentity() !== null
            && (string) ($_GET['action'] ?? '') === 'vibe-federation-callback') {
            return WhmcsSupportFlow::rejectedCallbackPage();
        }
        if (IdentityHandoff::currentMasqueradedIdentity() !== null) {
            $selected = filter_var($_POST['serviceid'] ?? $_GET['serviceid'] ?? 0, FILTER_VALIDATE_INT);
            return VibeSupportClientArea::render($selected > 0 ? (int) $selected : 0);
        }
        $identity = IdentityHandoff::currentIdentity();
        $callback = !$post && FederationFlow::handlesCallback($_GET);
        $serviceId = filter_var($_POST['serviceid'] ?? $_GET['serviceid'] ?? 0, FILTER_VALIDATE_INT);
        $result = null;
        if ($callback) { $result = FederationFlow::complete($_GET); }
        elseif ($post) {
            $valid = false;
            try { $valid = function_exists('check_token') && check_token('WHMCS.default') !== false; } catch (\Throwable) { }
            $result = is_array($identity) && $serviceId > 0 && $valid
                ? FederationFlow::begin((int) $serviceId, $identity, 'access')
                : ['status' => 'error'];
        }
        return ['pagetitle' => 'BladeCP Vibe', 'templatefile' => 'vibe-federation', 'requirelogin' => true, 'forcessl' => true,
            'vars' => ['serviceId' => $serviceId ?: 0, 'transitionUrl' => (string) ($result['redirectTo'] ?? ''),
                'transitionError' => !is_array($identity) || ($result !== null && ($result['status'] ?? '') === 'error'),
                'autoSubmit' => !$post && !$callback && is_array($identity) && $serviceId > 0,
            ]];
    }
}
