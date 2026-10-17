<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Retains only an opaque commercial handoff ticket across the normal WHMCS
 * login. It is never a redirect authority and is consumed before redemption.
 */
final class CommercialActionResume
{
    private const SESSION_KEY = 'bladecp_commercial_action_resume';
    private const TTL_SECONDS = 300;
    private const ROUTE = 'index.php?m=bladecp&action=commercial-handoff';

    public static function rememberTicketFromRequest(?int $now = null): void
    {
        if (!self::isExactRoute() || !isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }
        $ticket = $_GET['ticket'] ?? null;
        if (!is_string($ticket) || !self::isTicket($ticket)) {
            return;
        }
        $_SESSION[self::SESSION_KEY] = [
            'createdAt' => $now ?? time(),
            'ticket' => $ticket,
        ];
    }

    public static function ticket(?int $now = null): ?string
    {
        if (!self::hasValidIntent($now) || !isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $ticket = $_SESSION[self::SESSION_KEY]['ticket'] ?? null;
        return is_string($ticket) && self::isTicket($ticket) ? $ticket : null;
    }

    public static function consumeTicket(?int $now = null): ?string
    {
        $ticket = self::ticket($now);
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::SESSION_KEY]);
        }
        return $ticket;
    }

    public static function footerOutput(): string
    {
        if (self::onRoute() || !self::hasValidIntent() || self::authenticatedClientId() <= 0) {
            return '';
        }
        return '<script>(function(){try{window.location.replace(' . json_encode(self::ROUTE, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');}catch(e){}})();</script>'
            . '<noscript><p><a href="' . self::ROUTE . '">Continuar</a></p></noscript>';
    }

    public static function hasValidIntent(?int $now = null): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return false;
        }
        $intent = $_SESSION[self::SESSION_KEY] ?? null;
        $createdAt = is_array($intent) ? filter_var($intent['createdAt'] ?? null, FILTER_VALIDATE_INT) : false;
        $current = $now ?? time();
        if ($createdAt === false || $createdAt <= 0 || $createdAt > $current || ($current - $createdAt) > self::TTL_SECONDS) {
            unset($_SESSION[self::SESSION_KEY]);
            return false;
        }
        return is_array($intent) && is_string($intent['ticket'] ?? null) && self::isTicket($intent['ticket']);
    }

    private static function isTicket(string $ticket): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/D', $ticket) === 1;
    }

    private static function isExactRoute(): bool
    {
        return (string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'commercial-handoff';
    }

    private static function onRoute(): bool
    {
        return self::isExactRoute()
            || (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'm=bladecp')
                && str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'action=commercial-handoff'));
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
