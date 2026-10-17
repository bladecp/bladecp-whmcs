<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Adds the paid Vibe activation entry point to the native WHMCS product page.
 *
 * The hook receives a broad, theme-dependent array. It deliberately trusts
 * only the service model id and re-derives every authorization and service
 * predicate from WHMCS before rendering a form.
 */
final class VibeServiceIdentityCta
{
    private static bool $registered = false;
    /** @var (callable(string):mixed)|null */
    private static $tokenGeneratorForTests = null;

    public static function registerHook(): void
    {
        if (self::$registered || !function_exists('add_hook')) {
            return;
        }
        self::$registered = true;
        add_hook('ClientAreaProductDetailsOutput', 1, static function (array $vars): string {
            try {
                return self::render($vars);
            } catch (\Throwable) {
                return '';
            }
        });
    }

    /** @param array<string,mixed> $vars */
    public static function render(array $vars): string
    {
        $serviceId = self::serviceId($vars['service'] ?? null);
        if ($serviceId === null) {
            return '';
        }

        $decision = IdentityHandoff::currentIdentityDecision();
        $identity = $decision['identity'] ?? null;
        if (($decision['status'] ?? '') !== 'ready' || !is_array($identity)) {
            return '';
        }
        $clientId = self::positiveId($identity['clientId'] ?? null);
        if ($clientId === null) {
            return '';
        }

        // A fresh handoff and the exact interrupted-link retry are the only
        // two states that can expose this POST entry point.
        if (VibeServiceIdentityActivation::identityContextForService($serviceId, $clientId) === null
            && VibeServiceIdentityActivation::linkedPendingContextForService($serviceId, $clientId) === null) {
            return '';
        }

        $token = self::token();
        if ($token === '') {
            return '';
        }

        $id = (string) $serviceId;
        $copy = ClientCopy::domain('client-flows');
        return '<section class="bladecp-vibe-service-identity-cta" aria-label="' . self::escape($copy['cta_label']) . '">'
            . '<p>' . self::escape($copy['cta_text']) . '</p>'
            . '<form method="post" action="index.php?m=bladecp&amp;action=vibe-service-identity">'
            . $token
            . '<input type="hidden" name="bladecp_action" value="vibe_service_identity_start">'
            . '<input type="hidden" name="serviceid" value="' . self::escape($id) . '">'
            . '<button type="submit" class="btn btn-primary">' . self::escape($copy['cta_button']) . '</button>'
            . '</form></section>';
    }

    private static function serviceId(mixed $service): ?int
    {
        if (!is_object($service)) {
            return null;
        }
        $id = $service->id ?? null;
        if ($id === null && method_exists($service, 'getAttribute')) {
            try {
                $id = $service->getAttribute('id');
            } catch (\Throwable) {
                return null;
            }
        }
        return self::positiveId($id);
    }

    private static function positiveId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0
            ? (int) $value
            : null;
    }

    private static function token(): string
    {
        if (is_callable(self::$tokenGeneratorForTests)) {
            try {
                $token = (self::$tokenGeneratorForTests)('WHMCS.default');
                return is_string($token) && trim($token) !== '' ? $token : '';
            } catch (\Throwable) {
                return '';
            }
        }
        if (!function_exists('generate_token')) {
            return '';
        }
        try {
            $token = generate_token('WHMCS.default');
            return is_string($token) && trim($token) !== '' ? $token : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
