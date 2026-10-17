<?php
declare(strict_types=1);
namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Api\VibeIdentityLink;
use WHMCS\Module\Server\Bladecp\Config\Environments;
use WHMCS\Module\Server\Bladecp\Helpers\FederationConfig;
use WHMCS\Module\Server\Bladecp\Helpers\FederationLogout;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Server\Bladecp\Helpers\VibeSsoDestination;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Browser-bound federation. All identity and ownership come from WHMCS. */
final class FederationFlow
{
    private const PENDING = 'bladecp_federation_pending';

    public static function handlesCallback(array $query): bool
    {
        if (($query['action'] ?? '') === 'vibe-federation-callback') { return true; }
        $state = $query['state'] ?? '';
        return ($query['action'] ?? '') === 'vibe-identity-callback' && self::opaque($state)
            && isset($_SESSION[self::PENDING][hash('sha256', $state)]);
    }

    /** May be called only after the native owner and CSRF gates. */
    public static function begin(int $serviceId, array $identity, string $kind, ?string $launchToken = null): array
    {
        try {
            $context = self::context($serviceId, $identity, $kind);
            if ($context === null || !FederationConfig::enabled($context['serverId'])) { return self::error(); }
            $config = FederationConfig::forServer($context['serverId']);
            if ($config === null) { return self::error(); }
            $state = FederationConfig::nonce();
            $fingerprint = FederationConfig::sessionFingerprint();
            FederationLogout::track($context['serverId'], $identity['userId'], $config['installationId'], $fingerprint);
            $diagnostics = VibeActivationDiagnostics::context($serviceId, substr(hash('sha256', $state), 0, 16));
            $result = VibeActivationDiagnostics::run('start', $diagnostics, fn () => $context['client']->post('/vibe/federation/start', [
                'installationId' => $config['installationId'], 'returnState' => $state,
            ]), $context['client']);
            $url = self::vibeUrl($context['client'], $result, '/api/reseller/federation/start');
            if ($url === null) { return self::error(); }
            $pending = $_SESSION[self::PENDING] ?? [];
            $pending = is_array($pending) ? array_filter($pending, static fn ($row) => is_array($row) && ($row['expiresAt'] ?? 0) > time()) : [];
            // Keep independent attempts bounded; an older tab can safely retry.
            if (count($pending) >= 5) { array_shift($pending); }
            $pending[hash('sha256', $state)] = [
                'stage' => 'browser', 'kind' => $kind, 'serviceId' => $serviceId,
                'userId' => $identity['userId'], 'clientId' => $identity['clientId'],
                'serverId' => $context['serverId'], 'planUuid' => $context['planUuid'],
                'installationId' => $config['installationId'], 'keyId' => $config['keyId'],
                'sessionFingerprint' => $fingerprint, 'expiresAt' => time() + 600,
                'launchToken' => self::opaque($launchToken) ? $launchToken : null,
            ];
            $_SESSION[self::PENDING] = $pending;
            VibeFreeTrace::record('federation_start', substr(hash('sha256', $state), 0, 16), ['outcome' => 'ok']);
            return ['status' => 'link_required', 'redirectTo' => $url];
        } catch (\Throwable) { return self::error(); }
    }

    public static function complete(array $query): array
    {
        $release = null;
        $stage = 'federation_identity';
        $traceId = VibeFreeTrace::id();
        try {
            $state = $query['state'] ?? null;
            if (!self::opaque($state)) { return self::error(); }
            $key = hash('sha256', $state);
            $traceId = substr($key, 0, 16);
            $pending = $_SESSION[self::PENDING][$key] ?? null;
            $identity = IdentityHandoff::currentIdentity();
            if (!is_array($pending) || !is_array($identity) || $pending['expiresAt'] <= time()
                || $pending['userId'] !== $identity['userId'] || $pending['clientId'] !== $identity['clientId']
                || !hash_equals($pending['sessionFingerprint'], FederationConfig::sessionFingerprint())) { return self::error(); }
            $release = \WHMCS\Module\Server\Bladecp\Helpers\VibeServiceOperationLock::acquire((int) $pending['serviceId']);
            if ($release === null) { return self::error(); }
            $context = self::context($pending['serviceId'], $identity, $pending['kind']);
            $config = FederationConfig::forServer($pending['serverId']);
            if ($context === null || $context['serverId'] !== $pending['serverId'] || $context['planUuid'] !== $pending['planUuid']
                || $config === null || ($config['enabled'] ?? false) !== true
                || $config['installationId'] !== $pending['installationId'] || $config['keyId'] !== $pending['keyId']) { return self::error(); }
            FederationLogout::track($context['serverId'], $identity['userId'], $config['installationId'], $pending['sessionFingerprint']);
            $client = $context['client'];
            $diagnostics = VibeActivationDiagnostics::context((int) $pending['serviceId'], $traceId);
            if ($pending['stage'] === 'browser') {
                if (($query['action'] ?? '') !== 'vibe-federation-callback' || !self::uuid($query['attemptId'] ?? null)
                    || array_diff(array_keys($query), ['m', 'action', 'state', 'attemptId']) !== []) { return self::error(); }
                $verifier = FederationConfig::nonce();
                $body = VibeIdentityLink::issuePayload('whmcs-user:' . $identity['userId'], 'whmcs-client:' . $identity['clientId'],
                    (string) $pending['serviceId'], $context['planUuid'], gmdate('c'),
                    FederationConfig::encode(hash('sha256', $verifier, true)), hash('sha256', $state));
                $intent = VibeActivationDiagnostics::run('intent', $diagnostics, fn () => VibeIdentityLink::normalizeIssueResponse($client->post('/vibe/federation/intents', $body + [
                    'installationId' => $config['installationId'], 'browserAttemptId' => $query['attemptId'], 'sessionFingerprint' => $pending['sessionFingerprint'],
                ])), $client);
                if (($intent['status'] ?? '') !== 'link_required' || !self::uuid($intent['intentId'] ?? null)) { return self::error(); }
                $pending['intentId'] = $intent['intentId']; $pending['codeVerifier'] = $verifier;
                $user = (new \WHMCS\Authentication\CurrentUser())->user();
                $email = trim((string) ($user->email ?? ''));
                $name = trim((string) ($user->first_name ?? $user->firstName ?? '') . ' ' . (string) ($user->last_name ?? $user->lastName ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) { return self::error(); }
                $assertion = FederationConfig::assertion($pending['serverId'], [
                    'sub' => 'whmcs-user:' . $identity['userId'], 'intentId' => $intent['intentId'], 'browserAttemptId' => $query['attemptId'],
                    'sessionFingerprint' => $pending['sessionFingerprint'], 'externalClientAccountId' => 'whmcs-client:' . $identity['clientId'],
                    'externalServiceId' => (string) $pending['serviceId'], 'email' => $email, 'name' => $name !== '' ? mb_substr($name, 0, 200) : 'Cliente Vibe',
                ]);
                $proof = VibeActivationDiagnostics::run('identity', $diagnostics, fn () => $client->post('/vibe/federation/intents/' . rawurlencode($intent['intentId']) . '/authenticate', ['assertion' => $assertion]), $client);
                unset($assertion);
                VibeFreeTrace::record('federation_identity', $traceId, ['outcome' => ($proof['status'] ?? '') === 'identity_proved' ? 'ready' : (($proof['status'] ?? '') === 'identity_proof_required' ? 'link_required' : 'rejected')]);
                if (($proof['status'] ?? '') === 'identity_proof_required') {
                    $url = self::secureUrl($client, $intent['authorizationUrl'] ?? null, $intent['intentId'], $state);
                    if ($url === null) { return self::error(); }
                    $pending['stage'] = 'secure'; $_SESSION[self::PENDING][$key] = $pending;
                    return ['status' => 'link_required', 'redirectTo' => $url];
                }
                if (($proof['status'] ?? '') !== 'identity_proved' || !self::opaque($proof['authorizationCode'] ?? null)) { return self::error(); }
                $code = $proof['authorizationCode'];
            } elseif ($pending['stage'] === 'secure') {
                if (($query['action'] ?? '') !== 'vibe-identity-callback' || !self::opaque($query['code'] ?? null)
                    || array_diff(array_keys($query), ['m', 'action', 'state', 'code']) !== []) { return self::error(); }
                $code = $query['code'];
            } else { return self::error(); }
            unset($_SESSION[self::PENDING][$key]);
            $stage = 'federation_exchange';
            $exchange = self::exchangeWithRecovery($client, $pending, $code, $traceId);
            VibeFreeTrace::record('federation_exchange', $traceId, ['outcome' => ($exchange['status'] ?? '') === 'ready' ? 'ready' : 'error']);
            if (($exchange['status'] ?? '') !== 'ready') { return self::error(); }
            $stage = 'federation_finalize';
            if ($pending['kind'] === 'free') {
                $saved = VibeActivationDiagnostics::run('finalize', $diagnostics, fn () => VibeFreeActivation::finalizeIdentityExchange($pending['serviceId'], $exchange, $traceId, null, true));
            } elseif ($pending['kind'] === 'service') {
                $saved = VibeActivationDiagnostics::run('finalize', $diagnostics, fn () => VibeServiceIdentityActivation::finalizeIdentityExchange($pending['serviceId'], $identity['clientId'], $exchange, true));
            } else {
                $saved = VibeActivationDiagnostics::run('finalize', $diagnostics, fn () => VibeServiceIdentityActivation::finalizeActiveIdentityExchange($pending['serviceId'], $identity['clientId'], $exchange));
            }
            VibeFreeTrace::record('federation_finalize', $traceId, ['outcome' => ($saved['ok'] ?? false) === true ? 'ok' : 'error']);
            if (($saved['ok'] ?? false) !== true) { return self::error(); }
            $stage = 'federation_sso';
            $sso = VibeActivationDiagnostics::run('sso', $diagnostics, fn () => $client->post('/vibe/federation/sso', ['intentId' => $pending['intentId'], 'codeVerifier' => $pending['codeVerifier']]
                + ($pending['launchToken'] !== null ? ['launchToken' => $pending['launchToken']] : [])), $client);
            $url = self::vibeUrl($client, $sso, '/api/reseller/federation/consume');
            VibeFreeTrace::record('federation_sso', $traceId, ['outcome' => $url === null ? 'error' : 'ready']);
            return $url === null ? self::error() : ['status' => 'ready', 'redirectTo' => $url];
        } catch (\Throwable $error) {
            VibeFreeTrace::record($stage, $traceId, ['outcome' => $error instanceof ApiException ? 'api_error' : 'exception']);
            return self::error();
        }
        finally { if ($release !== null) { $release(); } }
    }

    /** Retry only an ambiguous transport failure, with the identical proof and key. */
    private static function exchangeWithRecovery(Client $client, array $pending, string $code, string $traceId): array
    {
        $idempotencyKey = 'federation-exchange-' . hash('sha256', $pending['intentId'] . $code);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return VibeActivationDiagnostics::run('exchange', VibeActivationDiagnostics::context((int) $pending['serviceId'], $traceId), fn () => $client->exchangeVibeIdentityLinkIntent(
                    $pending['intentId'], $code, $pending['codeVerifier'], $idempotencyKey
                ), $client);
            } catch (ApiException $error) {
                if ($attempt !== 0 || !in_array($error->httpStatus, [0, 502, 503, 504], true)) {
                    throw $error;
                }
                VibeFreeTrace::record('federation_exchange', $traceId, ['outcome' => 'unavailable']);
            }
        }
        return self::error();
    }

    public static function serviceEnabled(int $serviceId): bool
    {
        try {
            $serverId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('server');
            return FederationConfig::enabled($serverId);
        } catch (\Throwable) { return false; }
    }

    private static function context(int $serviceId, array $identity, string $kind): ?array
    {
        if (IdentityHandoff::currentIdentity() !== $identity) { return null; }
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $identity['clientId'])->first();
        if ($service === null || !in_array(strtolower((string) $service->domainstatus), ['active', 'pending'], true)) { return null; }
        $serviceFields = self::serviceFields($serviceId, (int) $service->packageid);
        if ($serviceFields === null) { return null; }
        $accessState = strtolower(trim((string) ($serviceFields['BladeCP Vibe Access State'] ?? '')));
        if (!in_array($accessState, ['', 'active'], true)) { return null; }
        if ($kind === 'free') { $prepared = VibeFreeActivation::identityContextForService($serviceId); }
        elseif ($kind === 'service') { $prepared = VibeServiceIdentityActivation::identityContextForService($serviceId, $identity['clientId']); }
        elseif ($kind === 'access' && strtolower((string) $service->domainstatus) === 'active') {
            $product = Capsule::table('tblproducts')->where('id', (int) $service->packageid)->first();
            if ($product === null || $product->servertype !== 'bladecp' || $product->configoption1 !== 'vibe') { return null; }
            $params = ServerConfig::paramsForServerId((int) $service->server);
            if ($params === null) { return null; }
            $params['configoption2'] = $product->configoption2;
            $planUuid = VibePlanReference::fromParams($params);
            if ($planUuid === null) { return null; }
            $prepared = ['client' => Client::fromParams($params), 'planUuid' => $planUuid];
        } else { return null; }
        return $prepared === null ? null : $prepared + ['serverId' => (int) $service->server];
    }

    /** @return array<string,string>|null */
    private static function serviceFields(int $serviceId, int $productId): ?array
    {
        try {
            $values = [];
            foreach (Capsule::table('tblcustomfields')->where('type', 'product')->where('relid', $productId)->get() as $field) {
                $fieldId = (int) ($field->id ?? 0);
                $name = trim((string) ($field->fieldname ?? ''));
                if ($fieldId <= 0 || $name === '') { continue; }
                $value = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $fieldId)
                    ->where('relid', $serviceId)->value('value');
                if (is_scalar($value)) { $values[$name] = (string) $value; }
            }
            return $values;
        } catch (\Throwable) { return null; }
    }

    private static function vibeUrl(Client $client, array $response, string $path): ?string
    {
        $url = $response['url'] ?? null; $expires = strtotime((string) ($response['expiresAt'] ?? ''));
        return VibeSsoDestination::isAllowed($url, $client->baseUrl()) && parse_url($url, PHP_URL_PATH) === $path
            && !parse_url($url, PHP_URL_FRAGMENT) && $expires !== false && $expires > time() ? $url : null;
    }
    private static function secureUrl(Client $client, mixed $url, string $intentId, string $state): ?string
    {
        $host = parse_url($client->baseUrl(), PHP_URL_HOST);
        $secure = Environments::byApiHost(is_string($host) ? $host : '')['secureHost'] ?? null;
        if ($secure === null || $url !== 'https://' . $secure . '/partner-link/' . $intentId) { return null; }
        return $url . '?state=' . rawurlencode($state);
    }
    private static function opaque(mixed $value): bool { return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1; }
    private static function uuid(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value) === 1; }
    private static function error(): array { return ['status' => 'error', 'error' => ClientCopy::text('client-flows', 'vibe_open_failed')]; }
}
