<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Validates Vibe SSO redirects against the configured platform API environment. */
final class VibeSsoDestination
{
    /** @var array<string,string> */
    private const API_TO_VIBE_HOST = [
        'api.bladecp.cloud' => 'bladecp.work',
        'api.bladecp.com' => 'bladecp.app',
    ];

    public static function isAllowed(mixed $url, string $apiBaseUrl): bool
    {
        if (!is_string($url) || trim($url) === '') {
            return false;
        }
        $destination = parse_url($url);
        $api = parse_url($apiBaseUrl);
        if (!is_array($destination) || !is_array($api)
            || strtolower((string) ($destination['scheme'] ?? '')) !== 'https'
            || !isset($destination['host'], $api['host'])
            || isset($destination['user'], $destination['pass'])) {
            return false;
        }
        $apiHost = strtolower(rtrim((string) $api['host'], '.'));
        $destinationHost = strtolower(rtrim((string) $destination['host'], '.'));
        $allowedHost = self::API_TO_VIBE_HOST[$apiHost] ?? null;
        if ($allowedHost !== null) {
            return hash_equals($allowedHost, $destinationHost)
                && (int) ($destination['port'] ?? 443) === 443;
        }

        if (!self::isLoopbackHost($apiHost) || !hash_equals($apiHost, $destinationHost)) {
            return false;
        }
        return (int) ($api['port'] ?? 443) === (int) ($destination['port'] ?? 443);
    }

    /**
     * The recovery result is returned only to the fixed Vibe completion
     * endpoint. A generic Vibe SSO URL is not sufficient here because it
     * could otherwise turn an authenticated API response into an open redirect.
     */
    public static function isContextRecoveryCallback(mixed $url, string $apiBaseUrl): bool
    {
        if (!self::isAllowed($url, $apiBaseUrl) || !is_string($url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== '/api/auth/context-resolution/recovery/complete') {
            return false;
        }
        $query = $parts['query'] ?? '';
        return $query === '';
    }

    private static function isLoopbackHost(string $host): bool
    {
        return $host === 'localhost' || $host === '::1' || $host === '127.0.0.1';
    }
}
