<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Safe, temporary diagnostics for the authenticated Vibe Free transition. */
final class VibeFreeTrace
{
    private const EVENTS = [
        'identity_gate', 'route_enter', 'csrf_check_start', 'csrf_check_finish',
        'activation_start', 'activation_finish',
        'identity_intent_finish',
        'claim_start', 'claim_finish',
        'order_accept_start', 'order_accept_finish',
        'module_create_start', 'module_create_finish',
        'federation_start', 'federation_identity', 'federation_exchange',
        'federation_finalize', 'federation_sso',
    ];

    private const OUTCOMES = [
        'valid', 'invalid', 'ok', 'error',
        'created', 'existing', 'rejected', 'exception', 'invalid_service',
        'active', 'pending', 'success', 'api_error', 'unavailable',
        'account_present', 'account_missing', 'ready', 'link_required',
    ];

    private const METHODS = ['get', 'post'];

    private const IDENTITY_STATUSES = [
        'ready', 'runtime_unavailable', 'not_authenticated',
        'masquerading_admin', 'account_context_unavailable',
        'ownership_api_unavailable', 'not_owner', 'invalid_identity',
        'runtime_error',
    ];

    private const MODULE_CREATE_REASONS = [
        'plan_missing', 'email_policy', 'service_missing', 'owner_invalid',
        'api_contract', 'persistence', 'api_unavailable', 'other',
    ];

    private const IDENTITY_INTENT_REASONS = [
        'local_context', 'callback_invalid', 'auth', 'not_found', 'conflict',
        'contract', 'unavailable', 'local_contract', 'transport',
        'request_error', 'response_contract',
    ];

    private const SAFE_FIELDS = [
        'method', 'post_action_matches', 'session_active',
        'session_cookie_present', 'session_uid_present',
        'authenticated_client_present', 'vars_client_present',
        'vars_csrf_present', 'emitted_csrf_present', 'post_csrf_present',
        'request_secure', 'identity_status', 'outcome', 'reason', 'redirect_present',
        'contract_field',
    ];

    public static function id(mixed $candidate = null): string
    {
        $value = strtolower(trim((string) $candidate));
        if (preg_match('/^[a-f0-9]{16}$/', $value) === 1) {
            return $value;
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return substr(hash('sha256', uniqid('vibe-free', true)), 0, 16);
        }
    }

    /** @param array<string,bool|string> $fields */
    public static function record(string $event, string $traceId, array $fields = []): void
    {
        $safe = self::payload($event, $traceId, $fields);
        if ($safe === null || PHP_SAPI === 'cli') {
            return;
        }

        error_log('[bladecp:vibe-free] ' . json_encode(
            $safe,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @param array<string,bool|string> $fields @return array<string,bool|string>|null */
    public static function payload(string $event, string $traceId, array $fields = []): ?array
    {
        if (!in_array($event, self::EVENTS, true)
            || preg_match('/^[a-f0-9]{16}$/', $traceId) !== 1) {
            return null;
        }

        $safe = ['event' => $event, 'trace' => $traceId];
        foreach (self::SAFE_FIELDS as $field) {
            if (!array_key_exists($field, $fields)) {
                continue;
            }
            $value = $fields[$field];
            if (is_bool($value)) {
                $safe[$field] = $value;
                continue;
            }
            $normalized = strtolower(trim((string) $value));
            if ($field === 'method' && in_array($normalized, self::METHODS, true)) {
                $safe[$field] = $normalized;
                continue;
            }
            if ($field === 'outcome' && in_array($normalized, self::OUTCOMES, true)) {
                $safe[$field] = $normalized;
                continue;
            }
            if ($field === 'identity_status'
                && $event === 'identity_gate'
                && in_array($normalized, self::IDENTITY_STATUSES, true)) {
                $safe[$field] = $normalized;
                continue;
            }
            if ($field === 'reason'
                && $event === 'module_create_finish'
                && in_array($normalized, self::MODULE_CREATE_REASONS, true)) {
                $safe[$field] = $normalized;
                continue;
            }
            if ($field === 'reason'
                && $event === 'identity_intent_finish'
                && in_array($normalized, self::IDENTITY_INTENT_REASONS, true)) {
                $safe[$field] = $normalized;
                continue;
            }
            if ($field === 'contract_field'
                && $event === 'identity_intent_finish'
                && in_array($normalized, [
                    'provider', 'externalusersubject', 'externalclientaccountid',
                    'externalserviceid', 'partnerplanuuid', 'partnerauthat',
                    'proof', 'codechallenge', 'codechallengemethod', 'statehash',
                    'audience', 'returntarget',
                ], true)) {
                $safe[$field] = $normalized;
            }
        }

        return $safe;
    }
}
