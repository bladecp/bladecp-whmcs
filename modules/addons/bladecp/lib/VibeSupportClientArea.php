<?php

declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;
use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Routes native Login as Owner to support for the selected client's service. */
final class VibeSupportClientArea
{
    public static function serviceId(?int $requested = null): ?int
    {
        $identity = IdentityHandoff::currentMasqueradedIdentity();
        if ($identity === null) { return null; }
        try {
            $query = Capsule::table('tblhosting')->where('userid', $identity['clientId']);
            if ($requested !== null) { $query->where('id', $requested); }
            $matches = [];
            foreach ($query->get() as $service) {
                if (VibeSupportLaunch::contextForService((int) $service->id) !== null) { $matches[] = (int) $service->id; }
            }
            return count($matches) === 1 ? $matches[0] : null;
        } catch (\Throwable) { return null; }
    }

    public static function render(?int $requested = null, string $entryError = '', bool $captureVerifiedStart = false): array
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0'); header('Referrer-Policy: no-referrer'); header('Pragma: no-cache');
        }
        $identity = IdentityHandoff::currentMasqueradedIdentity();
        $serviceId = $entryError === '' ? self::serviceId($requested) : null;
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($entryError === '' && $serviceId === null) {
            self::logGate('service_context');
        }
        if (!$post && $captureVerifiedStart && $entryError === '' && $identity !== null && $serviceId !== null) {
            \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::captureLaunchTokenForSupport(
                $identity['clientId'], $serviceId
            );
        }
        $launchToken = $identity !== null && $serviceId !== null
            ? \WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume::supportLaunchToken($identity['clientId'], $serviceId)
            : null;
        $result = null;
        if ($post && $serviceId !== null) {
            $valid = false;
            $stage = 'csrf_unavailable';
            try {
                // WHMCS throws or terminates on an invalid token. On success,
                // supported versions may return null, so only an explicit false
                // rejects the native POST.
                if (function_exists('check_token')) {
                    $valid = check_token('WHMCS.default') !== false;
                    $stage = $valid ? 'csrf_accepted' : 'csrf_rejected';
                }
            } catch (\Throwable) { $stage = 'csrf_exception'; }
            self::logGate($stage);
            // Native Owner support uses its own locally attested handoff. The
            // separate addon administrator support path remains unchanged.
            if ($valid) { $result = WhmcsSupportFlow::begin($serviceId, $launchToken); }
        }
        return ['pagetitle' => 'BladeCP Vibe', 'templatefile' => 'vibe-federation', 'requirelogin' => true, 'forcessl' => true,
            'vars' => ['serviceId' => $serviceId ?? 0, 'transitionUrl' => (string) ($result['authorizeUrl'] ?? ''),
                'transitionError' => $entryError !== '' || $serviceId === null || ($post && ($result['ok'] ?? false) !== true),
                'transitionMessage' => $entryError !== '' ? $entryError : (string) ($result['error'] ?? ''),
                'autoSubmit' => !$post && $serviceId !== null,
            ]];
    }

    /** Writes no service, client, session, token or exception data. */
    private static function logGate(string $stage): void
    {
        try { Logger::logCall('vibe_whmcs_owner_support_gate', ['stage' => $stage], ['ok' => $stage === 'csrf_accepted']); }
        catch (\Throwable) { }
        if ($stage !== 'csrf_accepted' && function_exists('logActivity')) {
            try { logActivity('BladeCP Vibe Owner SSO: gate=' . $stage); } catch (\Throwable) { }
        }
    }
}
