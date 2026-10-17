<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Resolves the canonical state needed for a safe Vibe lifecycle mutation. */
final class LifecycleTransition
{
    /**
     * @return array{complete:bool,idempotencyKey:?string,snapshot:array{status:string,resourceVersion:int}}|null
     */
    public static function resolve(
        Client $client,
        string $accountId,
        mixed $serviceId,
        string $operation,
        string $targetStatus
    ): ?array {
        try {
            $snapshot = AccountResponse::snapshot(
                $client->get('/vibe/accounts/' . rawurlencode($accountId)),
                $accountId
            );
        } catch (\Throwable) {
            return null;
        }

        if ($snapshot === null) {
            return null;
        }

        // The current Customer API lifecycle representation carries the
        // commercial status but not its serving projection. A reactivation
        // therefore cannot be considered complete from status=active alone:
        // the API must reconcile a possible commercial_lifecycle serving
        // suspension. The API owns the safe no-op for an already active
        // account and does not turn an inactivity serving suspension into a
        // commercial reactivation.
        if (
            !hash_equals($operation, 'reactivate')
            && hash_equals($targetStatus, $snapshot['status'])
        ) {
            return [
                'complete' => true,
                'idempotencyKey' => null,
                'snapshot' => $snapshot,
            ];
        }

        return [
            'complete' => false,
            'idempotencyKey' => 'whmcs-' . self::serviceId($serviceId)
                . '-vibe-' . $operation . '-r' . $snapshot['resourceVersion'],
            'snapshot' => $snapshot,
        ];
    }

    private static function serviceId(mixed $value): string
    {
        $serviceId = trim((string) $value);

        return $serviceId === '' ? '0' : $serviceId;
    }
}
