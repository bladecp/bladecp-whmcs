<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Keeps a short, server-owned Vibe Free intent across the normal WHMCS login
 * flow. It contains no customer, product or destination supplied by the
 * browser, so it cannot become an open redirect authority.
 */
final class VibeFreeResume
{
    private const SESSION_KEY = 'bladecp_vibe_free_resume';
    private const SUPPORT_SESSION_KEY = 'bladecp_vibe_support_resume';
    private const TTL_SECONDS = 600;
    private const ROUTE = 'index.php?m=bladecp&action=vibe-free';

    /**
     * Retains only the opaque, one-time Vibe launch handle. The actual prompt
     * and attachment stay outside WHMCS, in BladeCP Vibe storage.
     */
    public static function rememberLaunchTokenFromRequest(?int $now = null): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION) || !self::isExactVibeFreeRoute()) {
            return false;
        }
        $token = $_GET['start'] ?? null;
        if (!is_string($token) || !self::isLaunchToken($token)) {
            return false;
        }
        if (!self::consumeStartProofCookie($token)) {
            return false;
        }
        $_SESSION[self::SESSION_KEY] = [
            'createdAt' => $now ?? time(),
            'launchToken' => $token,
        ];
        return true;
    }

    public static function launchToken(?int $now = null): ?string
    {
        if (!self::hasValidIntent($now) || !isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $token = $_SESSION[self::SESSION_KEY]['launchToken'] ?? null;
        return is_string($token) && self::isLaunchToken($token) ? $token : null;
    }

    public static function consumeLaunchToken(?int $now = null): ?string
    {
        $token = self::launchToken($now);
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::SESSION_KEY]);
        }
        return $token;
    }

    /**
     * Moves a verified prompt handle into the exact native Owner support
     * context. The token is retained until the downstream support issuance
     * succeeds, but cannot be reused by a different client or service.
     */
    public static function captureLaunchTokenForSupport(int $clientId, int $serviceId, ?int $now = null): ?string
    {
        if ($clientId <= 0 || $serviceId <= 0 || !isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $token = self::launchToken($now);
        if ($token === null) {
            return null;
        }
        $_SESSION[self::SUPPORT_SESSION_KEY] = [
            'createdAt' => $now ?? time(),
            'clientId' => $clientId,
            'serviceId' => $serviceId,
            'launchToken' => $token,
        ];
        unset($_SESSION[self::SESSION_KEY]);
        return $token;
    }

    public static function supportLaunchToken(int $clientId, int $serviceId, ?int $now = null): ?string
    {
        if ($clientId <= 0 || $serviceId <= 0 || !isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $context = $_SESSION[self::SUPPORT_SESSION_KEY] ?? null;
        if (!is_array($context) || !self::isSupportContextFresh($context, $now)) {
            unset($_SESSION[self::SUPPORT_SESSION_KEY]);
            return null;
        }
        if ((int) ($context['clientId'] ?? 0) !== $clientId || (int) ($context['serviceId'] ?? 0) !== $serviceId) {
            return null;
        }
        $token = $context['launchToken'] ?? null;
        return is_string($token) && self::isLaunchToken($token) ? $token : null;
    }

    public static function consumeSupportLaunchToken(int $clientId, int $serviceId, ?int $now = null): ?string
    {
        $token = self::supportLaunchToken($clientId, $serviceId, $now);
        if ($token !== null && isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::SUPPORT_SESSION_KEY]);
        }
        return $token;
    }

    public static function rememberGuestIntent(?int $now = null): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        $launchToken = $_SESSION[self::SESSION_KEY]['launchToken'] ?? null;
        $_SESSION[self::SESSION_KEY] = ['createdAt' => $now ?? time()]
            + (is_string($launchToken) && self::isLaunchToken($launchToken)
                ? ['launchToken' => $launchToken]
                : []);
    }

    public static function consumeIntent(?int $now = null): bool
    {
        $valid = self::hasValidIntent($now);
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        return $valid;
    }

    /** @param array<string,mixed> $vars */
    public static function footerOutput(array $vars): string
    {
        if (self::onVibeFreeRoute() || !self::hasValidIntent()) {
            return '';
        }
        if (self::authenticatedClientId() <= 0) {
            return '';
        }

        $route = self::ROUTE;
        return '<script>(function(){try{window.location.replace(' . json_encode($route, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');}catch(e){}})();</script>'
            . '<noscript><p><a href="' . $route . '">Continuar para o BladeCP Vibe</a></p></noscript>';
    }

    public static function hasValidIntent(?int $now = null): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return false;
        }
        $intent = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($intent)) {
            return false;
        }
        $createdAt = filter_var($intent['createdAt'] ?? null, FILTER_VALIDATE_INT);
        $current = $now ?? time();
        if ($createdAt === false || $createdAt <= 0 || $createdAt > $current || ($current - $createdAt) > self::TTL_SECONDS) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $context */
    private static function isSupportContextFresh(array $context, ?int $now): bool
    {
        $createdAt = filter_var($context['createdAt'] ?? null, FILTER_VALIDATE_INT);
        $current = $now ?? time();
        return $createdAt !== false && $createdAt > 0 && $createdAt <= $current
            && ($current - $createdAt) <= self::TTL_SECONDS;
    }

    private static function isLaunchToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1;
    }

    /**
     * The Site sets a secure, host-only proof cookie just before redirecting
     * here. JavaScript cannot set HttpOnly, but expiration may add it safely.
     * It is intentionally tied to this exact opaque token and is consumed on
     * the first arrival, so a copied start URL alone cannot start generation.
     */
    private static function consumeStartProofCookie(string $token): bool
    {
        $name = self::startProofCookieName($token);
        $value = $_COOKIE[$name] ?? null;
        $valid = is_string($value) && hash_equals('1', $value);
        unset($_COOKIE[$name]);

        if (!headers_sent()) {
            setcookie($name, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
        }

        return $valid;
    }

    private static function startProofCookieName(string $token): string
    {
        return '__Host-bladecp-vibe-start-' . substr(hash('sha256', $token), 0, 24);
    }

    /** Accept launch input only at the initial, fixed Vibe Free route. */
    private static function isExactVibeFreeRoute(): bool
    {
        return (string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'vibe-free';
    }

    private static function onVibeFreeRoute(): bool
    {
        if ((string) ($_GET['m'] ?? '') === 'bladecp' && (string) ($_GET['action'] ?? '') === 'vibe-free') {
            return true;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return str_contains($uri, 'm=bladecp') && str_contains($uri, 'action=vibe-free');
    }

    private static function authenticatedClientId(): int
    {
        if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) {
            return 0;
        }
        try {
            $client = (new \WHMCS\Authentication\CurrentUser())->client();
            if ($client === null) {
                return 0;
            }
            $id = $client->id ?? (method_exists($client, 'getAttribute') ? $client->getAttribute('id') : null);
            return filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 ? (int) $id : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
