<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Strict server-to-server boundary for a stale Vibe context recovery. */
final class VibeContextRecovery
{
    /** @return array{ticket:string,externalUserSubject:string,externalClientAccountId:string} */
    public static function redeemPayload(string $ticket, string $subject, string $client): array
    {
        return [
            'ticket' => self::ticket($ticket),
            'externalUserSubject' => self::whmcsRef($subject, 'whmcs-user'),
            'externalClientAccountId' => self::whmcsRef($client, 'whmcs-client'),
        ];
    }

    /** @return array{recoveryId:string,externalServiceId:int,accountId:string,bindingVersion:int,action:string} */
    public static function normalizeRedeemResponse(array $response): array
    {
        $id = self::opaque($response['recoveryId'] ?? null);
        $serviceId = filter_var($response['externalServiceId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $accountId = self::opaque($response['accountId'] ?? null);
        $version = filter_var($response['bindingVersion'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $action = trim((string) ($response['action'] ?? ''));
        if ($id === null || $serviceId === false || $accountId === null || $version === false
            || !hash_equals('vibe_context_reauthorize', $action)) {
            throw new \UnexpectedValueException('Resposta inválida da recuperação de contexto Vibe.');
        }
        return [
            'recoveryId' => $id,
            'externalServiceId' => (int) $serviceId,
            'accountId' => $accountId,
            'bindingVersion' => (int) $version,
            'action' => $action,
        ];
    }

    /** @return array{recoveryId:string,externalUserSubject:string,externalClientAccountId:string} */
    public static function confirmPayload(string $recoveryId, string $subject, string $client): array
    {
        return [
            'recoveryId' => self::opaque($recoveryId) ?? throw new \InvalidArgumentException('Recuperação Vibe inválida.'),
            'externalUserSubject' => self::whmcsRef($subject, 'whmcs-user'),
            'externalClientAccountId' => self::whmcsRef($client, 'whmcs-client'),
        ];
    }

    /** @return array{callbackUrl:string,resultCode:string,expiresAt:string} */
    public static function normalizeConfirmResponse(array $response): array
    {
        $callbackUrl = self::httpsUrl($response['callbackUrl'] ?? null);
        $resultCode = self::ticket($response['resultCode'] ?? '');
        $expiresAt = self::futureTimestamp($response['expiresAt'] ?? null);
        if ($callbackUrl === null || $resultCode === null || $expiresAt === null) {
            throw new \UnexpectedValueException('Resposta inválida da confirmação de contexto Vibe.');
        }
        return ['callbackUrl' => $callbackUrl, 'resultCode' => $resultCode, 'expiresAt' => $expiresAt];
    }

    private static function ticket(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1 ? $value : null;
    }

    private static function whmcsRef(string $value, string $prefix): string
    {
        $value = trim($value);
        if (preg_match('/^' . preg_quote($prefix, '/') . ':[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Referência WHMCS inválida para recuperação de contexto.');
        }
        return $value;
    }

    private static function opaque(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' && strlen($value) <= 512 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? $value : null;
    }

    private static function futureTimestamp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            return null;
        }
        return $time->getTimestamp() > time()
            ? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z')
            : null;
    }

    private static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $url = trim($value);
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host']) && !isset($parts['user'], $parts['pass'])
            ? $url : null;
    }
}
