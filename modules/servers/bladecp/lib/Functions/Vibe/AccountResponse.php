<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Validates the semantic completion of Vibe lifecycle responses. */
final class AccountResponse
{
    /** @param array<string,mixed> $response */
    public static function lifecycle(array $response, string $accountId, array $states): bool
    {
        return hash_equals($accountId, trim((string) ($response['accountId'] ?? '')))
            && in_array(trim((string) ($response['status'] ?? '')), $states, true)
            && self::positiveInteger($response['resourceVersion'] ?? null)
            && self::positiveInteger($response['entitlementVersion'] ?? null);
    }

    /**
     * Returns the canonical lifecycle snapshot required before a mutation.
     *
     * A lifecycle operation must never manufacture an idempotency key from
     * locally inferred state. The Customer API owns both the account state and
     * its resource version.
     *
     * @return array{status:string,resourceVersion:int}|null
     */
    public static function snapshot(array $response, string $accountId): ?array
    {
        if (!hash_equals($accountId, trim((string) ($response['accountId'] ?? '')))) {
            return null;
        }

        $status = trim((string) ($response['status'] ?? ''));
        $resourceVersion = $response['resourceVersion'] ?? null;
        if ($status === '' || !self::positiveInteger($resourceVersion)) {
            return null;
        }

        return [
            'status' => $status,
            'resourceVersion' => (int) $resourceVersion,
        ];
    }

    private static function positiveInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }
}
