<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Valores de endpoint usados pelo cliente BladeCP. */
final class Endpoints
{
    /** Caminho da API anexado pelo Client ao host do Server record. */
    public const API_PATH = '/api/v1';

    public const DEFAULT_BASE = 'https://api.bladecp.com' . self::API_PATH;

    public const VIBE_IDENTITY_LINK_INTENTS = '/vibe/identity-link-intents';

    /** Short-lived operator-support launch intent, derived from the Server record. */
    public const VIBE_SUPPORT_INTENTS = '/vibe/support/intents';

    /** One-time partner-commercial handoff redemption, authenticated by Server Access Hash. */
    public const VIBE_COMMERCIAL_ACTION_TICKETS_REDEEM = '/vibe/commercial-action-tickets/redeem';

    /** Authenticated snapshot of local WHMCS Vibe offers. */
    public const VIBE_COMMERCIAL_CATALOG_SYNC = '/vibe/commercial-catalogs/sync';

    /** One-time recovery of a stale direct Vibe context. */
    public const VIBE_CONTEXT_RECOVERY_REDEEM = '/vibe/context-recovery/redeem';
    public const VIBE_CONTEXT_RECOVERY_CONFIRM = '/vibe/context-recovery/confirm';

    /** Public partner identity derived exclusively from the Server Access Hash. */
    public const PARTNER_BRANDING_ASSETS = '/branding/assets';

    public function __construct(private string $base = self::DEFAULT_BASE)
    {
        $this->base = rtrim($this->base, '/');
    }

    /** Base URL resolvida (ex.: `https://api.bladecp.com/api/v1`). */
    public function base(): string
    {
        return $this->base;
    }

    public function url(string $path): string
    {
        return $this->base . '/customer/' . ltrim($path, '/');
    }

    public static function vibeIdentityLinkIntentExchange(string $intentId): string
    {
        return self::VIBE_IDENTITY_LINK_INTENTS . '/'
            . rawurlencode($intentId) . '/exchange';
    }
}
