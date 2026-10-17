<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Keeps an account confirmation token across the native WHMCS login or sign
 * up, then returns once to the fixed confirmation route. The token is never a
 * redirect target and grants nothing by itself.
 */
final class MigrationClaimResume
{
    private const SESSION_KEY = 'bladecp_migration_claim';
    private const DONE_KEY = 'bladecp_migration_claim_done';
    private const TTL_SECONDS = 3600;
    private const ROUTE = 'index.php?m=bladecp&action=claim';

    public static function rememberTokenFromRequest(?int $now = null): void
    {
        if (!self::isExactRoute() || !isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        $token = $_GET['token'] ?? null;
        if (!is_string($token) || !self::isToken($token)) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = ['token' => $token, 'createdAt' => $now ?? time(), 'prompted' => false];
    }

    public static function token(?int $now = null): ?string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $intent = $_SESSION[self::SESSION_KEY] ?? null;
        $createdAt = is_array($intent) ? filter_var($intent['createdAt'] ?? null, FILTER_VALIDATE_INT) : false;
        $current = $now ?? time();
        if (!is_array($intent) || $createdAt === false || $createdAt <= 0 || $createdAt > $current
            || ($current - $createdAt) > self::TTL_SECONDS || !is_string($intent['token'] ?? null) || !self::isToken($intent['token'])) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }
        return $intent['token'];
    }

    /** The confirmation form is on screen: later pages must not bounce back to it. */
    public static function markPrompted(): void
    {
        if (isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY]['prompted'] = true;
        }
    }

    public static function forget(): void
    {
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /** Remembers, by hash only, that this session already confirmed the token. */
    public static function markDone(string $token): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION) || !self::isToken($token)) {
            return;
        }
        $done = is_array($_SESSION[self::DONE_KEY] ?? null) ? $_SESSION[self::DONE_KEY] : [];
        $done[hash('sha256', $token)] = time();
        $_SESSION[self::DONE_KEY] = array_slice($done, -5, null, true);
    }

    public static function isDone(string $token): bool
    {
        return isset($_SESSION) && is_array($_SESSION) && self::isToken($token)
            && is_array($_SESSION[self::DONE_KEY] ?? null) && isset($_SESSION[self::DONE_KEY][hash('sha256', $token)]);
    }

    public static function footerOutput(): string
    {
        if (self::onRoute() || self::token() === null || !empty($_SESSION[self::SESSION_KEY]['prompted'])
            || self::authenticatedClientId() <= 0) {
            return '';
        }
        return '<script>(function(){try{window.location.replace(' . json_encode(self::ROUTE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');}catch(e){}})();</script>'
            . '<noscript><p><a href="' . self::ROUTE . '">Continuar</a></p></noscript>';
    }

    public static function isToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9._~-]{16,256}$/D', $token) === 1;
    }

    private static function isExactRoute(): bool
    {
        return (string) ($_GET['m'] ?? '') === 'bladecp' && (string) ($_GET['action'] ?? '') === 'claim';
    }

    private static function onRoute(): bool
    {
        return self::isExactRoute()
            || (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'm=bladecp')
                && str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'action=claim'));
    }

    private static function authenticatedClientId(): int
    {
        if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) {
            return 0;
        }
        try {
            $client = (new \WHMCS\Authentication\CurrentUser())->client();
            $id = is_object($client) ? ($client->id ?? (method_exists($client, 'getAttribute') ? $client->getAttribute('id') : null)) : null;
            return filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0 ? (int) $id : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
