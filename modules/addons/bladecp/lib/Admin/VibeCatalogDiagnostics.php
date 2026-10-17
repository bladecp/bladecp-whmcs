<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\WebhookConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Informational only. Provisioning and sale decisions remain authoritative. */
final class VibeCatalogDiagnostics
{
    public static function plan(array $diagnostics, int $localProductId, string $tier = 'unknown'): string
    {
        if ($diagnostics === []) {
            return self::line('unknown', [], true);
        }
        $reason = $diagnostics['reasonCode'] ?? '';
        $known = ['ready', 'plan_configuration_required', 'plan_disabled', 'channel_disabled', 'enrollment_inactive', 'ai_mode_not_allowed', 'credentials_not_ready', 'managed_ai_dependencies'];
        $html = self::line(in_array($reason, $known, true) ? $reason : 'unknown', [], true);
        if (($diagnostics['profileStatus'] ?? '') !== 'active' || ($diagnostics['channel'] ?? '') !== 'whmcs_addon') {
            $html .= self::line('profile');
        }
        $host = $diagnostics['handoffHost'] ?? null;
        $localHost = parse_url(WebhookConfig::publicUrl(), PHP_URL_HOST);
        if (is_string($host) && is_string($localHost) && strcasecmp($host, $localHost) !== 0) {
            $html .= self::line('host', ['host' => $host, 'local' => $localHost]);
        }
        $mapped = (int) ($diagnostics['mappedProductId'] ?? 0);
        if ($mapped > 0 && $localProductId > 0 && $mapped !== $localProductId) {
            $html .= self::line('mapping', ['remote' => $mapped, 'local' => $localProductId]);
        } elseif ($localProductId === 0) {
            $html .= self::line($tier === 'free' ? 'import_free' : 'import');
        }
        if ($tier === 'free') {
            return $html;
        }
        if (($diagnostics['offerStatus'] ?? '') === 'disabled') {
            $html .= self::line('offer_disabled');
        } elseif (($diagnostics['priceStatus'] ?? '') !== 'fresh') {
            $html .= self::line('price');
        }
        return $html;
    }

    public static function attempt(?array $attempt): string
    {
        $reason = $attempt['reason'] ?? 'never';
        $known = ['synced', 'snapshot-incomplete', 'mapping-conflict', 'sync-failed', 'invalid-server', 'server-unavailable', 'never'];
        $key = in_array($reason, $known, true) ? $reason : 'sync-failed';
        return '<div class="bcp-vibe-sync-result">' . self::line('attempt.' . $key, ['count' => (int) ($attempt['count'] ?? 0)])
            . (is_string($attempt['attemptedAt'] ?? null) ? '<small>' . self::escape($attempt['attemptedAt']) . '</small>' : '') . '</div>';
    }

    private static function line(string $key, array $values = [], bool $primary = false): string
    {
        $text = self::escape(I18n::t('admin.vibe.' . $key, $values));
        return '<p class="bcp-vibe-diagnostic">' . ($primary ? '<strong>' . $text . '</strong>' : $text) . '</p>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
