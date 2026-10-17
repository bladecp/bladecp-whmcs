<?php
declare(strict_types=1);
namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\Bladecp\Functions\Vibe\IdentityHandoff;

if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }

/** Installation-specific signing key. API access keys are never signing keys. */
final class FederationConfig
{
    private const SUPPORT_EPOCH = 'bladecp_support_session_epoch';
    public static function forServer(int $serverId): ?array
    {
        if ($serverId <= 0) { return null; }
        try {
            $raw = Capsule::table('tbladdonmodules')->where('module', 'bladecp')
                ->where('setting', 'federation_server_' . $serverId)->value('value');
            $value = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
            return is_array($value) && isset($value['installationId'], $value['keyId'], $value['encryptedPrivateKey'], $value['publicKeyPem'], $value['issuer'], $value['callbackUrl']) ? $value : null;
        } catch (\Throwable) { return null; }
    }

    public static function enabled(int $serverId): bool
    {
        return (self::forServer($serverId)['enabled'] ?? false) === true;
    }

    /** Public registration is the only material that may be copied to the UI. */
    public static function registration(int $serverId): ?array
    {
        $config = self::forServer($serverId);
        return $config === null ? null : array_intersect_key($config, array_flip([
            'installationId', 'issuer', 'callbackUrl', 'keyId', 'publicKeyPem',
        ]));
    }

    /** Explicit admin action only. Rotation disables local federation first. */
    public static function generate(int $serverId, bool $rotate = false): bool
    {
        try {
            if (ServerConfig::paramsForServerId($serverId) === null || !function_exists('openssl_pkey_new') || !function_exists('localAPI')) { return false; }
            $old = self::forServer($serverId);
            if ($old !== null && !$rotate) { return false; }
            $callback = IdentityHandoff::callbackUrl();
            if ($callback === null || !str_starts_with($callback, 'https://')) { return false; }
            $callback = str_replace('action=vibe-identity-callback', 'action=vibe-federation-callback', $callback);
            $issuer = explode('/index.php?', $callback, 2)[0];
            if ($old !== null && ($old['issuer'] !== $issuer || $old['callbackUrl'] !== $callback)) { return false; }
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
            if ($key === false || !openssl_pkey_export($key, $private)) { return false; }
            $details = openssl_pkey_get_details($key);
            $encrypted = localAPI('EncryptPassword', ['password2' => $private]);
            $cipher = (string) ($encrypted['password'] ?? '');
            if (($encrypted['result'] ?? '') !== 'success' || $cipher === '' || hash_equals($private, $cipher) || str_contains($cipher, 'PRIVATE KEY')) { return false; }
            $roundtrip = localAPI('DecryptPassword', ['password2' => $cipher]);
            if (($roundtrip['result'] ?? '') !== 'success' || !hash_equals($private, (string) ($roundtrip['password'] ?? ''))) { return false; }
            unset($private, $roundtrip);
            if (!is_array($details) || !is_string($details['key'] ?? null)) { return false; }
            \WHMCS\Module\Server\Bladecp\Helpers\FederationLogout::ensureSchema();
            self::save($serverId, [
                'installationId' => $old['installationId'] ?? self::uuid(), 'issuer' => $issuer,
                'callbackUrl' => $callback, 'keyId' => self::uuid(), 'publicKeyPem' => $details['key'],
                'encryptedPrivateKey' => $cipher, 'enabled' => false,
            ], $old['keyId'] ?? null);
            return true;
        } catch (\Throwable) { return false; }
    }

    public static function setEnabled(int $serverId, bool $enabled): bool
    {
        try {
            $config = self::forServer($serverId);
            if ($config === null) { return false; }
            if ($enabled) {
                $client = ServerConfig::clientForServerId($serverId);
                if ($client === null) { return false; }
                $caps = $client->get('/vibe/federation/installations/' . rawurlencode($config['installationId']) . '/capabilities');
                if (($caps['available'] ?? false) !== true || ($caps['protocol'] ?? '') !== 'partner-federation-v2') { return false; }
                foreach (['installationId', 'issuer', 'callbackUrl', 'keyId'] as $field) {
                    if (!is_string($caps[$field] ?? null) || !hash_equals($config[$field], $caps[$field])) { return false; }
                }
                FederationLogout::ensureSchema();
            }
            $config['enabled'] = $enabled;
            self::save($serverId, $config, $config['keyId']);
            return true;
        } catch (\Throwable) { return false; }
    }

    public static function assertion(int $serverId, array $claims): string
    {
        $config = self::forServer($serverId);
        if ($config === null || ($config['enabled'] ?? false) !== true) { throw new \RuntimeException('federation_unavailable'); }
        $allowed = ['sub', 'intentId', 'browserAttemptId', 'sessionFingerprint', 'externalClientAccountId', 'externalServiceId', 'email', 'name'];
        if (count($claims) !== count($allowed) || array_diff(array_keys($claims), $allowed) !== []) { throw new \RuntimeException('invalid_assertion'); }
        $now = time();
        $payload = $claims + ['iss' => $config['issuer'], 'installationId' => $config['installationId'],
            'aud' => 'bladecp-secure:partner-federation-v2', 'iat' => $now, 'exp' => $now + 60,
            'jti' => self::nonce(), 'protocol' => 'partner-federation-v2'];
        return self::sign($config, ['alg' => 'RS256', 'typ' => 'partner-federation+jwt', 'kid' => $config['keyId']], $payload);
    }

    /** Assertion for the native WHMCS Owner support protocol only. */
    public static function supportAssertion(int $serverId, array $claims): string
    {
        $config = self::forServer($serverId);
        if ($config === null || ($config['enabled'] ?? false) !== true) { throw new \RuntimeException('federation_unavailable'); }
        $allowed = ['browserAttemptId', 'sessionFingerprint', 'externalAdminSubject', 'operatorName', 'externalClientAccountId', 'externalServiceId', 'accountId', 'bindingVersion', 'accessKind'];
        if (array_key_exists('replaceSessionId', $claims)) { $allowed[] = 'replaceSessionId'; }
        if (count($claims) !== count($allowed) || array_diff(array_keys($claims), $allowed) !== []) { throw new \RuntimeException('invalid_assertion'); }
        $now = time();
        $payload = $claims + ['iss' => $config['issuer'], 'installationId' => $config['installationId'], 'aud' => 'bladecp-support-v1',
            'iat' => $now, 'exp' => $now + 60, 'jti' => self::nonce(), 'protocol' => 'whmcs-support-v1'];
        return self::sign($config, ['alg' => 'RS256', 'typ' => 'bladecp-whmcs-support+jwt', 'kid' => $config['keyId']], $payload);
    }

    public static function nonce(): string { return self::encode(random_bytes(32)); }
    public static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    public static function sessionFingerprint(): string
    {
        $session = session_id();
        if ($session === '' || strlen($session) < 16) { throw new \RuntimeException('session_unavailable'); }
        return self::encode(hash('sha256', $session, true));
    }
    /** A return from customer impersonation may keep the native PHP session ID. */
    public static function supportSessionFingerprint(): string
    {
        $session = self::sessionFingerprint();
        $epoch = $_SESSION[self::SUPPORT_EPOCH] ?? null;
        if (!is_string($epoch) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $epoch) !== 1) {
            $epoch = self::nonce();
            $_SESSION[self::SUPPORT_EPOCH] = $epoch;
        }
        return self::encode(hash('sha256', 'support:' . $session . ':' . $epoch, true));
    }
    public static function resetSupportSession(): void
    {
        unset($_SESSION[self::SUPPORT_EPOCH]);
    }
    /** @param array<string,mixed> $config @param array<string,string> $header @param array<string,mixed> $payload */
    private static function sign(array $config, array $header, array $payload): string
    {
        $decrypted = localAPI('DecryptPassword', ['password2' => $config['encryptedPrivateKey']]);
        $private = (string) ($decrypted['password'] ?? '');
        if (($decrypted['result'] ?? '') !== 'success' || !str_contains($private, 'PRIVATE KEY')) { throw new \RuntimeException('federation_unavailable'); }
        $input = self::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.'
            . self::encode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($input, $signature, $private, OPENSSL_ALGO_SHA256)) { throw new \RuntimeException('federation_unavailable'); }
        unset($private, $decrypted);
        return $input . '.' . self::encode($signature);
    }
    private static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
    private static function save(int $serverId, array $value, ?string $expectedKeyId): void
    {
        Capsule::connection()->transaction(static function () use ($serverId, $value, $expectedKeyId): void {
            // Serializes key generation/rotation for the exact server record.
            Capsule::table('tblservers')->where('id', $serverId)->lockForUpdate()->first();
            if ((self::forServer($serverId)['keyId'] ?? null) !== $expectedKeyId) { throw new \RuntimeException('configuration_conflict'); }
            Capsule::table('tbladdonmodules')->updateOrInsert(['module' => 'bladecp', 'setting' => 'federation_server_' . $serverId],
                ['value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        });
    }
}
