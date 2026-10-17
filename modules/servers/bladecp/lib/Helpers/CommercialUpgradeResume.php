<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Server-session-only authority for a commercial target selected by BladeCP.
 * Product ids may appear in the native WHMCS form, but this state makes them
 * non-authoritative and rejects any substitution at the upgrade hook.
 */
final class CommercialUpgradeResume
{
    private const KEY = 'bladecp_commercial_upgrade_resume';
    private const TTL = 300;

    public static function remember(int $serviceId, int $productId, string $planUuid, int $clientId, string $billingCycle): bool
    {
        if ($serviceId <= 0 || $productId <= 0 || $clientId <= 0 || !self::isUuid($planUuid) || !self::isCycle($billingCycle) || !isset($_SESSION) || !is_array($_SESSION)) {
            return false;
        }
        try {
            $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (\Throwable) {
            return false;
        }
        $_SESSION[self::KEY] = [
            'createdAt' => time(),
            'serviceId' => $serviceId,
            'productId' => $productId,
            'planUuid' => $planUuid,
            'clientId' => $clientId,
            'billingCycle' => $billingCycle,
            'nonce' => $nonce,
            'status' => 'ready',
        ];
        return true;
    }

    /** @return array{serviceId:int,productId:int,planUuid:string,clientId:int,billingCycle:string,nonce:string,status:string}|null */
    public static function state(): ?array
    {
        $value = isset($_SESSION) && is_array($_SESSION) ? ($_SESSION[self::KEY] ?? null) : null;
        if (!is_array($value)) {
            return null;
        }
        $createdAt = filter_var($value['createdAt'] ?? null, FILTER_VALIDATE_INT);
        $serviceId = filter_var($value['serviceId'] ?? null, FILTER_VALIDATE_INT);
        $productId = filter_var($value['productId'] ?? null, FILTER_VALIDATE_INT);
        $clientId = filter_var($value['clientId'] ?? null, FILTER_VALIDATE_INT);
        $billingCycle = trim((string) ($value['billingCycle'] ?? ''));
        $nonce = trim((string) ($value['nonce'] ?? ''));
        $status = trim((string) ($value['status'] ?? ''));
        $planUuid = trim((string) ($value['planUuid'] ?? ''));
        if ($createdAt === false || $createdAt <= 0 || $createdAt > time() || time() - $createdAt > self::TTL
            || $serviceId === false || $serviceId <= 0 || $productId === false || $productId <= 0 || $clientId === false || $clientId <= 0 || !self::isUuid($planUuid) || !self::isCycle($billingCycle) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) !== 1 || !in_array($status, ['ready', 'bridging'], true)) {
            self::clear();
            return null;
        }
        return ['serviceId' => (int) $serviceId, 'productId' => (int) $productId, 'planUuid' => $planUuid, 'clientId' => (int) $clientId, 'billingCycle' => $billingCycle, 'nonce' => $nonce, 'status' => $status];
    }

    /** Null when no commercial flow is active, otherwise whether it matches. */
    public static function matches(int $serviceId, int $productId, int $clientId, string $billingCycle): ?bool
    {
        $state = self::state();
        if ($state === null) {
            return null;
        }
        return $state['serviceId'] === $serviceId && $state['productId'] === $productId && $state['clientId'] === $clientId && $state['billingCycle'] === $billingCycle;
    }

    /** @return array{serviceId:int,productId:int,planUuid:string,clientId:int,billingCycle:string,nonce:string,status:string}|null */
    public static function beginBridgeForCurrentClient(): ?array
    {
        $state = self::stateForCurrentClient();
        if ($state === null || $state['status'] !== 'ready') {
            return null;
        }
        $_SESSION[self::KEY]['status'] = 'bridging';
        $state['status'] = 'bridging';
        return $state;
    }

    /** Atomically consumes the one native browser submission, even on mismatch. */
    public static function consumeMatch(int $serviceId, int $productId, int $clientId, string $billingCycle, string $nonce): ?bool
    {
        $state = self::state();
        if ($state === null) {
            return null;
        }
        $matches = $state['status'] === 'bridging'
            && hash_equals($state['nonce'], $nonce)
            && $state['serviceId'] === $serviceId
            && $state['productId'] === $productId
            && $state['clientId'] === $clientId
            && $state['billingCycle'] === $billingCycle;
        self::clear();
        return $matches;
    }

    /** @return array{serviceId:int,productId:int,planUuid:string,clientId:int,billingCycle:string,nonce:string,status:string}|null */
    public static function stateForCurrentClient(): ?array
    {
        return self::stateForCurrentClientWithFailure()['state'];
    }

    /**
     * @return array{state:array{serviceId:int,productId:int,planUuid:string,clientId:int,billingCycle:string,nonce:string,status:string}|null,failure:'missing'|'expired'|'invalid'|'client_session_mismatch'|null}
     */
    public static function stateForCurrentClientWithFailure(): array
    {
        $value = isset($_SESSION) && is_array($_SESSION) ? ($_SESSION[self::KEY] ?? null) : null;
        if (!is_array($value)) {
            return ['state' => null, 'failure' => 'missing'];
        }

        $createdAt = filter_var($value['createdAt'] ?? null, FILTER_VALIDATE_INT);
        if ($createdAt !== false && $createdAt > 0 && $createdAt <= time() && time() - $createdAt > self::TTL) {
            self::clear();
            return ['state' => null, 'failure' => 'expired'];
        }

        $state = self::state();
        if ($state === null) {
            return ['state' => null, 'failure' => 'invalid'];
        }
        $current = self::currentClientId();
        if ($current === null || $current !== $state['clientId']) {
            self::clear();
            return ['state' => null, 'failure' => 'client_session_mismatch'];
        }
        return ['state' => $state, 'failure' => null];
    }

    public static function currentClientId(): ?int
    {
        if (class_exists(\WHMCS\Authentication\CurrentUser::class)) {
            try {
                $client = (new \WHMCS\Authentication\CurrentUser())->client();
                $id = is_object($client) ? ($client->id ?? (method_exists($client, 'getAttribute') ? $client->getAttribute('id') : null)) : null;
                if (filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0) {
                    return (int) $id;
                }
            } catch (\Throwable) {
            }
        }
        $fallback = filter_var($_SESSION['uid'] ?? null, FILTER_VALIDATE_INT);
        return $fallback !== false && (int) $fallback > 0 ? (int) $fallback : null;
    }

    public static function clear(): void
    {
        if (isset($_SESSION) && is_array($_SESSION)) {
            unset($_SESSION[self::KEY]);
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function isCycle(string $value): bool
    {
        return in_array($value, ['monthly', 'quarterly', 'semiannually', 'annually'], true);
    }
}
