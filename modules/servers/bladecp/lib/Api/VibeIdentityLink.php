<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Strict, redacted Customer API boundary for the Vibe identity-link flow. */
final class VibeIdentityLink
{
    /** @return array<string,mixed> */
    public static function issuePayload(string $subject, string $client, string $service, string $plan, string $assertedAt, string $challenge, string $stateHash): array
    {
        return [
            'provider' => 'whmcs',
            'externalUserSubject' => self::whmcsRef($subject, 'whmcs-user'),
            'externalClientAccountId' => self::whmcsRef($client, 'whmcs-client'),
            'externalServiceId' => self::digits($service),
            'partnerPlanUuid' => self::opaque($plan),
            'partnerAuthAt' => self::timestamp($assertedAt),
            'proof' => [
                'codeChallenge' => self::base64Url($challenge),
                'codeChallengeMethod' => 'S256',
                'stateHash' => self::sha256($stateHash),
            ],
            'audience' => 'bladecp-vibe',
            'returnTarget' => 'whmcs-vibe-free',
        ];
    }

    /** @return array<string,string> */
    public static function exchangePayload(string $code, string $verifier): array
    {
        return [
            'authorizationCode' => self::opaque($code),
            'codeVerifier' => self::base64Url($verifier),
        ];
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    public static function normalizeIssueResponse(array $response): array
    {
        $status = trim((string) ($response['status'] ?? ''));
        if ($status === 'ready') {
            return self::ready($response);
        }
        if ($status === 'link_required') {
            $intentId = self::responseOpaque($response['intentId'] ?? null);
            $url = self::httpsUrl($response['authorizationUrl'] ?? null);
            $expiresAt = self::futureTimestamp($response['expiresAt'] ?? null);
            if ($intentId !== null && $url !== null && $expiresAt !== null) {
                return ['status' => 'link_required', 'intentId' => $intentId, 'authorizationUrl' => $url, 'expiresAt' => $expiresAt];
            }
        }
        return ['status' => 'malformed'];
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    public static function normalizeExchangeResponse(array $response): array
    {
        return trim((string) ($response['status'] ?? '')) === 'ready'
            ? self::ready($response)
            : ['status' => 'malformed'];
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private static function ready(array $response): array
    {
        $accountId = self::responseOpaque($response['accountId'] ?? null);
        $version = filter_var($response['bindingVersion'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($accountId === null || $version === false || !is_bool($response['created'] ?? null)) {
            return ['status' => 'malformed'];
        }
        return ['status' => 'ready', 'accountId' => $accountId, 'bindingVersion' => (int) $version, 'created' => $response['created']];
    }

    private static function whmcsRef(string $value, string $prefix): string
    {
        $value = trim($value);
        if (preg_match('/^' . preg_quote($prefix, '/') . ':[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Referência WHMCS inválida para vínculo de identidade.');
        }
        return $value;
    }

    private static function digits(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Serviço inválido para vínculo de identidade.');
        }
        return $value;
    }

    private static function opaque(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 512 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Valor inválido para vínculo de identidade.');
        }
        return $value;
    }

    private static function timestamp(string $value): string
    {
        try {
            $time = new \DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Atestação inválida para vínculo de identidade.');
        }
        if ($time->getTimestamp() > time() + 60) {
            throw new \InvalidArgumentException('Atestação inválida para vínculo de identidade.');
        }
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');
    }

    private static function base64Url(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Prova inválida para vínculo de identidade.');
        }
        return $value;
    }

    private static function sha256(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Prova inválida para vínculo de identidade.');
        }
        return $value;
    }

    private static function responseOpaque(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        try { return self::opaque($value); } catch (\Throwable) { return null; }
    }

    private static function httpsUrl(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) return null;
        $url = trim($value); $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $url : null;
    }

    private static function futureTimestamp(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        try { $time = new \DateTimeImmutable(trim($value)); } catch (\Throwable) { return null; }
        return $time->getTimestamp() > time() ? $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z') : null;
    }
}
