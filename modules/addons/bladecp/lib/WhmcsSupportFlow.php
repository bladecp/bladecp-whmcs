<?php
declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Config\Environments;
use WHMCS\Module\Server\Bladecp\Helpers\FederationConfig;
use WHMCS\Module\Server\Bladecp\Helpers\FederationLogout;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\VibeFreeResume;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Native WHMCS administrative handoff. It never reuses partner federation. */
final class WhmcsSupportFlow
{
    private const SESSION_KEY = 'bladecp_whmcs_support_pending';
    private const TTL = 600;
    // Native role permission IDs in WHMCS 8/9, independent of display labels.
    private const VIEW_CLIENT_SERVICES = 11;
    private const PERFORM_MODULE_COMMANDS = 14;

    public static function handlesCallback(array $query): bool
    {
        $state = $query['state'] ?? null;
        return self::isOpaque($state) && isset($_SESSION[self::SESSION_KEY][hash('sha256', $state)]);
    }

    /** @return array{ok:bool,authorizeUrl?:string,error?:string} */
    public static function begin(int $serviceId, ?string $launchToken = null, bool $fromAdmin = false, ?string $replaceSessionId = null): array
    {
        $identity = IdentityHandoff::currentMasqueradedIdentity();
        $admin = self::activeAdmin($fromAdmin);
        if ($fromAdmin) {
            try { $identity = ['clientId' => (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid')]; }
            catch (\Throwable) { return self::failure('service_context'); }
        }
        if ($identity === null) { return self::failure('identity'); }
        if ($admin === null) { return self::failure('admin'); }
        if ($replaceSessionId !== null && !self::isUuid($replaceSessionId)) { return self::failure('confirmation'); }
        if (!self::isLaunchOrNull($launchToken)) { return self::failure('launch_token'); }
        $context = self::ownedContext($serviceId, $identity['clientId']);
        if ($context === null) { return self::failure('service_context'); }
        $config = FederationConfig::forServer($context['serverId']);
        if ($config === null || ($config['enabled'] ?? false) !== true || !self::isUuid($config['installationId'] ?? null)) {
            return self::failure('federation_config');
        }
        try {
            $fingerprint = FederationConfig::supportSessionFingerprint();
            $state = FederationConfig::nonce();
            $client = ServerConfig::clientForServerId($context['serverId']);
            if ($client === null) { return self::failure('server_client'); }
            FederationLogout::trackSupport($context['serverId'], $admin['id'], $config['installationId'], $fingerprint);
            $response = $client->post('/vibe/support/whmcs/start', [
                'installationId' => $config['installationId'], 'returnState' => $state,
            ]);
            $url = $response['authorizeUrl'] ?? null;
            if (!is_string($url) || !self::isStartUrl($url, $client->baseUrl())) { return self::failure('start_response'); }
            self::prune();
            $_SESSION[self::SESSION_KEY][hash('sha256', $state)] = [
                'expiresAt' => time() + self::TTL, 'serverId' => $context['serverId'], 'serviceId' => $context['serviceId'],
                'clientId' => $identity['clientId'], 'adminId' => $admin['id'], 'operatorName' => $admin['name'],
                'accountId' => $context['accountId'], 'bindingVersion' => $context['bindingVersion'],
                'installationId' => $config['installationId'], 'keyId' => $config['keyId'], 'sessionFingerprint' => $fingerprint,
                'replaceSessionId' => $replaceSessionId,
                'launchToken' => $launchToken, 'accessKind' => $fromAdmin ? 'native_admin' : 'native_owner',
            ];
            return ['ok' => true, 'authorizeUrl' => $url];
        } catch (\Throwable $error) { return self::failure('start_exception', $error); }
    }

    /** @return array<string,mixed> */
    public static function complete(array $query, bool $confirmSwitch = false): array
    {
        if (array_diff(array_keys($query), ['m', 'action', 'state', 'attemptId']) !== []) { return self::callbackFailure('query'); }
        $state = $query['state'] ?? null; $attemptId = $query['attemptId'] ?? null;
        if (!self::isOpaque($state) || !self::isOpaque($attemptId)) { return self::callbackFailure('shape'); }
        $key = hash('sha256', $state); $pending = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!is_array($pending) || (int) ($pending['expiresAt'] ?? 0) < time()) { unset($_SESSION[self::SESSION_KEY][$key]); return self::callbackFailure('pending'); }
        $fromAdmin = ($pending['accessKind'] ?? 'native_owner') === 'native_admin';
        $identity = $fromAdmin ? ['clientId' => (int) $pending['clientId']] : IdentityHandoff::currentMasqueradedIdentity();
        $admin = self::activeAdmin($fromAdmin);
        if ($confirmSwitch && (!self::isUuid($pending['conflictSessionId'] ?? null)
            || !hash_equals((string) ($pending['conflictAttemptId'] ?? ''), $attemptId))) { return self::callbackFailure('confirmation'); }
        if ($identity === null || $admin === null || (int) $pending['clientId'] !== $identity['clientId'] || (int) $pending['adminId'] !== $admin['id']) { return self::callbackFailure('identity'); }
        $phase = 'context';
        try {
            if (!hash_equals((string) $pending['sessionFingerprint'], FederationConfig::supportSessionFingerprint())) { return self::callbackFailure('fingerprint'); }
            $context = self::ownedContext((int) $pending['serviceId'], $identity['clientId']);
            $config = FederationConfig::forServer((int) $pending['serverId']);
            if ($context === null || $config === null || ($config['enabled'] ?? false) !== true
                || $context['serverId'] !== (int) $pending['serverId']
                || !hash_equals((string) $pending['installationId'], (string) $config['installationId'])
                || !hash_equals((string) $pending['keyId'], (string) $config['keyId'])
                || !hash_equals((string) $pending['accountId'], $context['accountId'])
                || (int) $pending['bindingVersion'] !== $context['bindingVersion']) { return self::callbackFailure('context'); }
            if ($confirmSwitch) {
                // Assertions and browser attempts remain single-use. Confirmation
                // starts a fresh browser-bound attempt carrying only the expected root.
                $restart = self::begin($context['serviceId'], $pending['launchToken'] ?? null, $fromAdmin, $pending['conflictSessionId']);
                if (($restart['ok'] ?? false) === true) { unset($_SESSION[self::SESSION_KEY][$key]); }
                return $restart;
            }
            $phase = 'track_session';
            FederationLogout::trackSupport($context['serverId'], $admin['id'], $config['installationId'], $pending['sessionFingerprint']);
            $phase = 'sign_assertion';
            $claims = [
                'browserAttemptId' => $attemptId, 'sessionFingerprint' => $pending['sessionFingerprint'],
                'externalAdminSubject' => 'whmcs-admin:' . $admin['id'], 'operatorName' => $admin['name'],
                'externalClientAccountId' => 'whmcs-client:' . $identity['clientId'], 'externalServiceId' => (string) $context['serviceId'],
                'accountId' => $context['accountId'], 'bindingVersion' => $context['bindingVersion'], 'accessKind' => $pending['accessKind'] ?? 'native_owner',
            ];
            if (is_string($pending['replaceSessionId'] ?? null)) { $claims['replaceSessionId'] = $pending['replaceSessionId']; }
            $assertion = FederationConfig::supportAssertion($context['serverId'], $claims);
            $body = ['installationId' => $config['installationId'], 'assertion' => $assertion];
            if (is_string($pending['launchToken'] ?? null)) { $body['launchToken'] = $pending['launchToken']; }
            $client = ServerConfig::clientForServerId($context['serverId']);
            if ($client === null) { return self::callbackFailure('server_client'); }
            $phase = 'authorize_api';
            $response = $client->post('/vibe/support/whmcs/authorize', $body);
            $url = $response['authorizeUrl'] ?? null;
            if (!is_string($url) || !self::isHandoffUrl($url, $client->baseUrl(), $attemptId, $pending['launchToken'] ?? null)) { return self::callbackFailure('authorize_response'); }
            unset($_SESSION[self::SESSION_KEY][$key]);
            if (is_string($pending['launchToken'] ?? null)) { VibeFreeResume::consumeSupportLaunchToken($identity['clientId'], $context['serviceId']); }
            return ['ok' => true, 'authorizeUrl' => $url];
        } catch (\WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException $error) {
            if ($error->httpStatus === 409 && ($error->body['code'] ?? '') === 'active_session_conflict'
                && self::isUuid($error->body['sessionId'] ?? null)) {
                $_SESSION[self::SESSION_KEY][$key]['conflictSessionId'] = $error->body['sessionId'];
                $_SESSION[self::SESSION_KEY][$key]['conflictAttemptId'] = $attemptId;
                return ['ok' => false, 'conflict' => true];
            }
            return self::callbackFailure($phase . '_exception', $error);
        } catch (\Throwable $error) { return self::callbackFailure($phase . '_exception', $error); }
    }

    /** @return array<string,mixed> */
    public static function renderCallback(array $query): array
    {
        $pending = $_SESSION[self::SESSION_KEY][hash('sha256', (string) ($query['state'] ?? ''))] ?? [];
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $valid = !$post;
        if ($post) {
            try { $valid = ($_POST['confirmSwitch'] ?? '') === '1' && function_exists('check_token') && check_token('WHMCS.default') !== false; }
            catch (\Throwable) { $valid = false; }
        }
        $result = $valid ? self::complete($query, $post) : self::callbackFailure('csrf');
        $page = self::page((string) ($result['authorizeUrl'] ?? ''), ($result['ok'] ?? false) !== true, false);
        // This response has already authenticated the native admin. A customer
        // login would discard the purpose of entering from the admin area.
        $page['requirelogin'] = ($pending['accessKind'] ?? '') !== 'native_admin';
        if (!$page['requirelogin']) { $page['vars']['transitionMessage'] = ClientCopy::text('client-flows', 'support_back_to_service'); }
        $page['vars']['supportConflict'] = ($result['conflict'] ?? false) === true;
        $page['vars']['supportCallbackUrl'] = 'index.php?m=bladecp&action=vibe-federation-callback&state='
            . rawurlencode((string) ($query['state'] ?? '')) . '&attemptId=' . rawurlencode((string) ($query['attemptId'] ?? ''));
        return $page;
    }

    /** Public renewal entry, authorized by the native administrator, never by a customer login. */
    public static function renderAdminEntry(): array
    {
        $serviceId = filter_var($_POST['serviceid'] ?? $_GET['serviceid'] ?? null, FILTER_VALIDATE_INT);
        $launchToken = $_POST['launchToken'] ?? $_GET['launchToken'] ?? null;
        $allowed = $serviceId !== false && $serviceId > 0 && self::isLaunchOrNull($launchToken) && self::activeAdmin(true) !== null;
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $result = null;
        if ($post) {
            $valid = false;
            try { $valid = function_exists('check_token') && check_token('WHMCS.default') !== false; } catch (\Throwable) { }
            $result = $allowed && $valid ? self::begin((int) $serviceId, $launchToken, true) : self::error();
        }
        $page = self::page((string) ($result['authorizeUrl'] ?? ''), !$allowed || ($post && ($result['ok'] ?? false) !== true), $allowed && !$post);
        $page['requirelogin'] = false;
        $page['vars']['serviceId'] = $serviceId ?: 0;
        $page['vars']['supportLaunchToken'] = $allowed ? $launchToken : null;
        $page['vars']['transitionFormUrl'] = 'index.php?m=bladecp&action=vibe-admin-support';
        $page['vars']['transitionMessage'] = ClientCopy::text('client-flows', 'support_back_to_service');
        return $page;
    }

    /** A callback-shaped request without a matching pending attempt is rejected and auditable. */
    public static function rejectedCallbackPage(): array
    {
        self::callbackFailure('unmatched');
        return self::page('', true, false);
    }

    /** @return array<string,mixed> */
    public static function page(string $url, bool $error, bool $autoSubmit): array
    {
        return ['pagetitle' => 'BladeCP Vibe', 'templatefile' => 'vibe-federation', 'requirelogin' => true, 'forcessl' => true,
            'vars' => ['transitionUrl' => $url, 'transitionError' => $error, 'autoSubmit' => $autoSubmit]];
    }

    /** @return array{serviceId:int,serverId:int,accountId:string,bindingVersion:int}|null */
    private static function ownedContext(int $serviceId, int $clientId): ?array
    {
        if ($serviceId <= 0 || $clientId <= 0) { return null; }
        try {
            $owner = Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
            if ((int) $owner !== $clientId) { return null; }
            $context = VibeSupportLaunch::contextForService($serviceId);
            return $context !== null && self::isUuid($context['accountId']) ? $context : null;
        } catch (\Throwable) { return null; }
    }

    /** @return array{id:int,name:string}|null */
    private static function activeAdmin(bool $fromAdmin = false): ?array
    {
        try {
            if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) { return null; }
            $current = new \WHMCS\Authentication\CurrentUser();
            if (!method_exists($current, 'isAuthenticatedAdmin') || !method_exists($current, 'isMasqueradingAdmin')
                || !method_exists($current, 'admin') || !$current->isAuthenticatedAdmin() || (!$fromAdmin && !$current->isMasqueradingAdmin())) { return null; }
            $native = $current->admin();
            if ($fromAdmin) {
                if (!is_object($native) || !method_exists($native, 'hasPermission')) { return self::adminFailure('permission_api'); }
                if (!$native->hasPermission(self::VIEW_CLIENT_SERVICES)) { return self::adminFailure('permission_service'); }
                if (!$native->hasPermission(self::PERFORM_MODULE_COMMANDS)) { return self::adminFailure('permission_command'); }
            }
            $id = self::objectId($native);
            if ($id === null) { return null; }
            $admin = Capsule::table('tbladmins')->where('id', (int) $id)->first();
            if ($admin === null || !empty($admin->disabled)) { return null; }
            $name = trim((string) ($admin->firstname ?? '') . ' ' . (string) ($admin->lastname ?? ''));
            $name = $name !== '' ? $name : trim((string) ($admin->username ?? ''));
            return $name === '' ? null : ['id' => (int) $id, 'name' => function_exists('mb_substr') ? mb_substr($name, 0, 200) : substr($name, 0, 200)];
        } catch (\Throwable $error) { return self::adminFailure($error instanceof \TypeError ? 'type_error' : 'runtime_error'); }
    }

    /** Bounded diagnostics only, without operator details or session values. */
    private static function adminFailure(string $stage): ?array
    {
        if (function_exists('logActivity')) {
            try { logActivity('BladeCP Vibe Admin SSO: denied=' . $stage); } catch (\Throwable) { }
        }
        return null;
    }

    public static function isAdminSession(): bool
    {
        try { return class_exists(\WHMCS\Authentication\CurrentUser::class)
            && (new \WHMCS\Authentication\CurrentUser())->isAuthenticatedAdmin(); }
        catch (\Throwable) { return false; }
    }

    private static function objectId(mixed $value): ?int
    {
        if (!is_object($value)) { return null; }
        $id = $value->id ?? (method_exists($value, 'getAttribute') ? $value->getAttribute('id') : null);
        return filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 ? (int) $id : null;
    }

    private static function prune(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) { $_SESSION[self::SESSION_KEY] = []; }
        foreach ($_SESSION[self::SESSION_KEY] as $key => $pending) { if (!is_array($pending) || (int) ($pending['expiresAt'] ?? 0) < time()) { unset($_SESSION[self::SESSION_KEY][$key]); } }
        while (count($_SESSION[self::SESSION_KEY]) >= 5) { array_shift($_SESSION[self::SESSION_KEY]); }
    }
    private static function isOpaque(mixed $value): bool { return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1; }
    private static function isLaunchOrNull(?string $value): bool { return $value === null || self::isOpaque($value); }
    private static function isUuid(mixed $value): bool { return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1; }
    private static function error(): array { return ['ok' => false, 'error' => ClientCopy::text('client-flows', 'support_session_failed')]; }
    /** Logs only a bounded internal stage, never session, token, payload or exception message. */
    private static function failure(string $stage, ?\Throwable $exception = null): array
    {
        try {
            Logger::logCall('vibe_whmcs_owner_support_begin', ['stage' => $stage], [
                'error' => true,
                'exceptionClass' => $exception === null ? '' : get_class($exception),
                'exceptionCode' => $exception === null ? 0 : (int) $exception->getCode(),
            ]);
        } catch (\Throwable) { }
        if (function_exists('logActivity')) {
            try { logActivity('BladeCP Vibe Owner SSO: begin=' . $stage . self::exceptionDiagnostic($exception)); } catch (\Throwable) { }
        }
        return self::error();
    }
    private static function callbackFailure(string $stage, ?\Throwable $exception = null): array
    {
        try {
            Logger::logCall('vibe_whmcs_owner_support_callback', ['stage' => $stage], [
                'error' => true,
                'exceptionClass' => $exception === null ? '' : get_class($exception),
                'exceptionCode' => $exception === null ? 0 : (int) $exception->getCode(),
            ]);
        } catch (\Throwable) { }
        if (function_exists('logActivity')) {
            try { logActivity('BladeCP Vibe Owner SSO: callback=' . $stage . self::exceptionDiagnostic($exception)); } catch (\Throwable) { }
        }
        return self::error();
    }
    /** Only fixed error categories and numeric HTTP status enter the activity log. */
    private static function exceptionDiagnostic(?\Throwable $error): string
    {
        if ($error === null) { return ''; }
        if ($error instanceof \WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException) {
            $code = $error->body['code'] ?? null;
            $allowed = ['invalid_federation_proof', 'invalid_browser_attempt', 'federation_unavailable', 'invalid_support_intent'];
            return ' http=' . $error->httpStatus . ' reason=' . (in_array($code, $allowed, true) ? $code : 'other_api_error');
        }
        return ' reason=' . ($error instanceof \TypeError ? 'type_error' : ($error instanceof \Error ? 'runtime_error' : 'operation_error'));
    }
    private static function appHost(string $base): ?string
    {
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));
        return Environments::byApiHost($host)['vibeHost'] ?? ($host === '127.0.0.1' ? '127.0.0.1' : null);
    }
    private static function validUrl(string $url, string $base, string $path, array $keys): bool
    {
        $parts = parse_url($url); $host = self::appHost($base);
        if (!is_array($parts) || $host === null || ($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== $host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== $path) { return false; }
        $rawQuery = (string) ($parts['query'] ?? '');
        $seen = [];
        foreach (explode('&', $rawQuery) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2 || $pair[0] === '' || isset($seen[$pair[0]]) || rawurldecode($pair[0]) !== $pair[0] || rawurldecode($pair[1]) !== $pair[1]) { return false; }
            $seen[$pair[0]] = $pair[1];
        }
        return count($seen) === count($keys) && array_diff(array_keys($seen), $keys) === [] && self::isOpaque($seen[$keys[0]] ?? null);
    }
    private static function isStartUrl(string $url, string $base): bool { return self::validUrl($url, $base, '/api/auth/support/whmcs/start', ['ticket']); }
    private static function isHandoffUrl(string $url, string $base, string $attemptId, mixed $launchToken): bool
    {
        $parts = parse_url($url); if (!is_array($parts)) { return false; } parse_str((string) ($parts['query'] ?? ''), $q);
        if (!self::validUrl($url, $base, '/api/auth/support/handoff', array_key_exists('launchToken', $q) ? ['code', 'attemptId', 'launchToken'] : ['code', 'attemptId'])) { return false; }
        return is_string($launchToken) === array_key_exists('launchToken', $q)
            && hash_equals($attemptId, (string) ($q['attemptId'] ?? ''))
            && (!is_string($launchToken) || hash_equals($launchToken, (string) ($q['launchToken'] ?? '')));
    }
}
