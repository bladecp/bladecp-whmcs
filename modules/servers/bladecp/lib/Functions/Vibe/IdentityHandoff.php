<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Config\Environments;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Executes the WHMCS side of the partner customer identity-link protocol.
 *
 * The commercial service and its partner context are resolved by the caller.
 * This class neither creates a service nor persists an account. It keeps only
 * short-lived PKCE material and opaque intent state in the authenticated
 * browser session, and intentionally does not log any handoff data.
 */
final class IdentityHandoff
{
    private const PENDING_SESSION_KEY = 'bladecp_vibe_identity_handoff_pending';
    private const TTL_SECONDS = 600;
    private const CALLBACK_PATH = '/index.php?m=bladecp&action=vibe-identity-callback';
    /**
     * Starts an identity link intent using server-resolved commercial context.
     *
     * @param array{userId:int|string,clientId:int|string,serviceId:int|string,partnerPlanUuid:string,partnerAuthAt:string} $trustedContext
     * @return array{status:'ready',accountId:string,bindingVersion:int,created:bool}|array{status:'link_required',redirectTo:string}|array{status:'error',reason:string}
     */
    public static function begin(Client $client, array $trustedContext): array
    {
        $context = self::normalizeContext($trustedContext);
        $callbackUrl = self::callbackUrl();
        if ($context === null || $callbackUrl === null || !self::hasSession()) {
            return self::error('local_context');
        }

        self::clearPending();
        $state = self::randomToken();
        $verifier = self::randomToken();
        $stateHash = hash('sha256', $state);
        $contextHash = self::contextHash($context);

        try {
            $response = $client->issueVibeIdentityLinkIntent(
                'whmcs-user:' . $context['userId'],
                'whmcs-client:' . $context['clientId'],
                $context['serviceId'],
                $context['partnerPlanUuid'],
                $context['partnerAuthAt'],
                self::codeChallenge($verifier),
                $stateHash,
                self::idempotencyKey('issue', $stateHash)
            );
        } catch (\Throwable $error) {
            return self::requestError($error);
        }

        return self::beginResult(
            $response,
            $state,
            $verifier,
            $stateHash,
            $contextHash,
            $callbackUrl
        );
    }

    /**
     * Consumes exactly one identity-link callback. The callback may contain
     * only state and code. State is consumed before the API exchange.
     *
     * @param array{userId:int|string,clientId:int|string,serviceId:int|string,partnerPlanUuid:string,partnerAuthAt:string} $trustedContext
     * @param array<string,mixed> $query
     * @return array{status:'ready',accountId:string,bindingVersion:int,created:bool}|array{status:'error',reason:string}
     */
    public static function complete(
        Client $client,
        array $trustedContext,
        array $query
    ): array {
        $context = self::normalizeContext($trustedContext);
        $callbackUrl = self::callbackUrl();
        if ($context === null) {
            return self::error('local_context');
        }
        $pending = self::consumePending($query, $context, $callbackUrl);
        if ($pending === null) {
            return self::error('callback_invalid');
        }

        try {
            $response = $client->exchangeVibeIdentityLinkIntent(
                $pending['intentId'],
                $pending['authorizationCode'],
                $pending['codeVerifier'],
                self::idempotencyKey(
                    'exchange',
                    hash('sha256', $pending['intentId'] . "\n" . $pending['authorizationCode'])
                )
            );
        } catch (\Throwable $error) {
            return self::requestError($error);
        }

        return self::completeResult($response);
    }

    /**
     * Derives the single callback from WHMCS' server-side System URL. Browser
     * input is never accepted as a callback or redirect authority.
     */
    public static function callbackUrl(): ?string
    {
        $systemUrl = self::systemUrl();
        return $systemUrl === null ? null : self::callbackUrlFromSystemUrl($systemUrl);
    }

    private static function callbackUrlFromSystemUrl(string $systemUrl): ?string
    {
        $systemUrl = trim($systemUrl);
        if ($systemUrl === '' || str_contains($systemUrl, '#')) {
            return null;
        }
        $parts = parse_url($systemUrl);
        if (!is_array($parts)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $loopback = $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
        if (($scheme !== 'https' && !($scheme === 'http' && $loopback))
            || $host === '' || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        $authority = $host;
        if ($port !== null && !(($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80))) {
            $authority .= ':' . $port;
        }
        $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($basePath !== '' && !str_starts_with($basePath, '/')) {
            return null;
        }

        return $scheme . '://' . $authority . $basePath . self::CALLBACK_PATH;
    }

    /** @param array<string,mixed> $response */
    private static function beginResult(
        array $response,
        string $state,
        string $verifier,
        string $stateHash,
        string $contextHash,
        string $callbackUrl
    ): array {
        $status = self::responseStatus($response);
        if ($status === 'ready') {
            return self::readyResult($response);
        }
        if ($status !== 'link_required') {
            return self::error('response_contract');
        }

        $intentId = trim((string) ($response['intentId'] ?? ''));
        $authorizationUrl = self::authorizationUrl($response['authorizationUrl'] ?? null, $state);
        if (!self::opaqueIntentId($intentId) || $authorizationUrl === null) {
            return self::error('response_contract');
        }

        $_SESSION[self::PENDING_SESSION_KEY] = [
            'stateHash' => $stateHash,
            'codeVerifier' => $verifier,
            'intentId' => $intentId,
            'contextHash' => $contextHash,
            'callbackUrl' => $callbackUrl,
            'expiresAt' => time() + self::TTL_SECONDS,
        ];

        return ['status' => 'link_required', 'redirectTo' => $authorizationUrl];
    }

    /** @param array<string,mixed> $response */
    private static function completeResult(array $response): array
    {
        $status = self::responseStatus($response);
        if ($status === 'ready') {
            return self::readyResult($response);
        }
        return self::error('response_contract');
    }

    /**
     * @param array<string,mixed> $query
     * @param array<string,string>|null $context
     * @return array{intentId:string,codeVerifier:string,authorizationCode:string}|null
     */
    private static function consumePending(array $query, ?array $context, ?string $callbackUrl): ?array
    {
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        if (!self::hasSession() || $context === null || $callbackUrl === null) {
            return null;
        }
        if ($keys !== ['action', 'code', 'm', 'state']
            || ($query['m'] ?? null) !== 'bladecp'
            || ($query['action'] ?? null) !== 'vibe-identity-callback') {
            // A malformed callback is still an attempted one-time callback.
            // Do not leave a usable state behind for a later replay.
            self::clearPending();
            return null;
        }
        $pending = $_SESSION[self::PENDING_SESSION_KEY] ?? null;
        unset($_SESSION[self::PENDING_SESSION_KEY]);
        if (!is_array($pending)
            || !self::validExpiry($pending['expiresAt'] ?? null)
            || !is_string($pending['stateHash'] ?? null)
            || !is_string($pending['codeVerifier'] ?? null)
            || !is_string($pending['intentId'] ?? null)
            || !is_string($pending['contextHash'] ?? null)
            || !is_string($pending['callbackUrl'] ?? null)) {
            return null;
        }

        $state = $query['state'];
        $code = $query['code'];
        if (!is_string($state) || !is_string($code)
            || !self::isToken($state) || !self::authorizationCode($code)
            || !hash_equals($pending['stateHash'], hash('sha256', $state))
            || !hash_equals($pending['contextHash'], $context['contextHash'])
            || !hash_equals($pending['callbackUrl'], $callbackUrl)
            || !self::opaqueIntentId($pending['intentId'])) {
            return null;
        }

        return [
            'intentId' => $pending['intentId'],
            'codeVerifier' => $pending['codeVerifier'],
            'authorizationCode' => $code,
        ];
    }

    /** @param array<string,mixed> $context @return array<string,string>|null */
    private static function normalizeContext(array $context): ?array
    {
        $userId = self::positiveId($context['userId'] ?? null);
        $clientId = self::positiveId($context['clientId'] ?? null);
        $serviceId = self::positiveId($context['serviceId'] ?? null);
        $planUuid = trim((string) ($context['partnerPlanUuid'] ?? ''));
        $assertionAt = trim((string) ($context['partnerAuthAt'] ?? ''));
        if ($userId === null || $clientId === null || $serviceId === null
            || !self::planUuid($planUuid) || !self::assertionAt($assertionAt)) {
            return null;
        }

        $normalized = [
            'userId' => $userId,
            'clientId' => $clientId,
            'serviceId' => $serviceId,
            'partnerPlanUuid' => $planUuid,
            'partnerAuthAt' => $assertionAt,
        ];
        $normalized['contextHash'] = self::contextHash($normalized);
        return $normalized;
    }

    /** @param array<string,string> $context */
    private static function contextHash(array $context): string
    {
        return hash('sha256', implode("\n", [
            $context['userId'],
            $context['clientId'],
            $context['serviceId'],
            $context['partnerPlanUuid'],
        ]));
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function hasSession(): bool
    {
        return isset($_SESSION) && is_array($_SESSION);
    }

    private static function clearPending(): void
    {
        if (self::hasSession()) {
            unset($_SESSION[self::PENDING_SESSION_KEY]);
        }
    }

    /** @param array<string,mixed> $response */
    private static function responseStatus(array $response): string
    {
        $status = strtolower(trim((string) ($response['status'] ?? '')));
        return in_array($status, ['ready', 'link_required'], true) ? $status : '';
    }

    private static function authorizationUrl(mixed $url, string $state): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $allowedHosts = Environments::secureHosts();
        if (PHP_SAPI === 'cli') $allowedHosts[] = 'secure.bladecp.test';
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === '' || isset($parts['user'], $parts['pass'], $parts['fragment'])
            || !in_array($host, $allowedHosts, true)) {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (!is_array($query) || isset($query['state'])) {
            return null;
        }
        $query['state'] = $state;
        $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $authority = strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }
        return 'https://' . $authority . (string) ($parts['path'] ?? '/') . '?' . $parts['query'];
    }

    private static function positiveId(mixed $value): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            return null;
        }
        return (string) (int) $value;
    }

    private static function planUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private static function assertionAt(string $value): bool
    {
        return strlen($value) <= 64 && strtotime($value) !== false;
    }

    private static function opaqueIntentId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{16,256}$/', $value) === 1;
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $value) === 1;
    }

    private static function authorizationCode(string $value): bool
    {
        return $value !== '' && strlen($value) <= 2048 && preg_match('/^[A-Za-z0-9._~\/-]+$/', $value) === 1;
    }

    private static function validExpiry(mixed $value): bool
    {
        $expiresAt = filter_var($value, FILTER_VALIDATE_INT);
        return $expiresAt !== false && (int) $expiresAt >= time();
    }

    private static function requestErrorReason(\Throwable $error): string
    {
        if ($error instanceof \InvalidArgumentException || $error instanceof \TypeError) {
            return 'local_contract';
        }

        $status = $error instanceof ApiException
            ? $error->httpStatus
            : self::compatibleHttpStatus($error);

        if (($error instanceof ApiException || self::apiExceptionShape($error))
            && $status === 0) {
            return 'transport';
        }

        return match ($status) {
            401, 403 => 'auth',
            404 => 'not_found',
            409 => 'conflict',
            400, 422 => 'contract',
            429, 500, 502, 503, 504 => 'unavailable',
            default => 'request_error',
        };
    }

    private static function compatibleHttpStatus(\Throwable $error): int
    {
        if (property_exists($error, 'httpStatus')) {
            /** @var mixed $candidate */
            $candidate = $error->httpStatus;
            return is_int($candidate) ? $candidate : 0;
        }
        return (int) $error->getCode();
    }

    private static function apiExceptionShape(\Throwable $error): bool
    {
        return property_exists($error, 'httpStatus');
    }

    /** @return array{status:'error',reason:string,contractField?:string} */
    private static function requestError(\Throwable $error): array
    {
        $result = self::error(self::requestErrorReason($error));
        $contractField = self::contractField($error);
        if ($contractField !== null) {
            $result['contractField'] = $contractField;
        }
        return $result;
    }

    private static function contractField(\Throwable $error): ?string
    {
        if (!property_exists($error, 'body') || !is_array($error->body)) {
            return null;
        }
        $issues = $error->body['error']['issues']
            ?? $error->body['issues']
            ?? null;
        if (!is_array($issues)) {
            return null;
        }
        $allowed = [
            'provider', 'externalUserSubject', 'externalClientAccountId',
            'externalServiceId', 'partnerPlanUuid', 'partnerAuthAt', 'proof',
            'codeChallenge', 'codeChallengeMethod', 'stateHash', 'audience',
            'returnTarget',
        ];
        foreach ($issues as $issue) {
            $path = is_array($issue) ? ($issue['path'] ?? null) : null;
            if (!is_array($path)) {
                continue;
            }
            foreach (array_reverse($path) as $segment) {
                if (is_string($segment) && in_array($segment, $allowed, true)) {
                    return $segment;
                }
            }
        }
        return null;
    }

    /** @return array{status:'error',reason:string} */
    private static function error(string $reason): array
    {
        return ['status' => 'error', 'reason' => $reason];
    }

    private static function systemUrl(): ?string
    {
        try {
            if (class_exists(\WHMCS\Config\Setting::class)) {
                $value = \WHMCS\Config\Setting::getValue('SystemURL');
                if (is_string($value) && trim($value) !== '') {
                    return $value;
                }
            }
        } catch (\Throwable) {
            // The module must fail closed when the trusted WHMCS setting is unavailable.
        }

        global $CONFIG;
        $value = is_array($CONFIG) ? ($CONFIG['SystemURL'] ?? null) : null;
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string,mixed> $response */
    private static function readyResult(array $response): array
    {
        $accountId = trim((string) ($response['accountId'] ?? ''));
        $bindingVersion = filter_var(
            $response['bindingVersion'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($accountId === '' || $bindingVersion === false
            || !is_bool($response['created'] ?? null)) {
            return self::error('response_contract');
        }
        return [
            'status' => 'ready',
            'accountId' => $accountId,
            'bindingVersion' => (int) $bindingVersion,
            'created' => $response['created'],
        ];
    }

    private static function idempotencyKey(string $operation, string $material): string
    {
        return 'whmcs-vibe-identity-' . $operation . '-' . hash('sha256', $material);
    }
}
