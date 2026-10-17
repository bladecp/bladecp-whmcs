<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Resolves the WHMCS email-verification policy and Free grant assurance. */
final class VibeEmailVerification
{
    public const VERIFIED = 'verified';
    public const NOT_REQUIRED = 'not_required';

    /** @param array<string,mixed> $params @return array{assurance:?string,confirmationRequired:bool} */
    public static function evaluate(array $params): array
    {
        $clientId = self::clientId($params);
        if ($clientId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return self::denied(false);
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblclients')) {
                return self::denied(false);
            }
            $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first();
            return $client === null ? self::denied(false) : self::evaluateForClient($client);
        } catch (\Throwable) {
            return self::denied(false);
        }
    }

    /** @return array{assurance:?string,confirmationRequired:bool} */
    public static function evaluateForClient(object $client): array
    {
        $enabled = self::verificationEnabled();
        if ($enabled === null) {
            return self::denied(false);
        }
        if (!$enabled) {
            return ['assurance' => self::NOT_REQUIRED, 'confirmationRequired' => false];
        }
        if (!self::hasVerificationColumn()) {
            return self::denied(false);
        }
        if (self::isConfirmedValue($client->email_verified ?? null)) {
            return ['assurance' => self::VERIFIED, 'confirmationRequired' => true];
        }

        return self::denied(true);
    }

    /** @param array<string,mixed> $params */
    public static function assurance(array $params): ?string
    {
        return self::evaluate($params)['assurance'];
    }

    /** @param array<string,mixed> $params */
    public static function isConfirmed(array $params): bool
    {
        return self::assurance($params) === self::VERIFIED;
    }

    public static function isConfirmedValue(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        return is_string($value) && in_array(strtolower(trim($value)), ['yes', 'true', 'on'], true);
    }

    private static function verificationEnabled(): ?bool
    {
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return null;
            }
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblconfiguration')
                || !$schema->hasColumn('tblconfiguration', 'setting')
                || !$schema->hasColumn('tblconfiguration', 'value')) {
                return null;
            }
            $row = \WHMCS\Database\Capsule::table('tblconfiguration')
                ->where('setting', 'EnableEmailVerification')
                ->first();
            if ($row === null) {
                return null;
            }
            $value = strtolower(trim((string) ($row->value ?? '')));
            if (in_array($value, ['', '0', 'false', 'off', 'no'], true)) {
                return false;
            }
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function hasVerificationColumn(): bool
    {
        try {
            return class_exists(\WHMCS\Database\Capsule::class)
                && \WHMCS\Database\Capsule::schema()->hasTable('tblclients')
                && \WHMCS\Database\Capsule::schema()->hasColumn('tblclients', 'email_verified');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{assurance:null,confirmationRequired:bool} */
    private static function denied(bool $confirmationRequired): array
    {
        return ['assurance' => null, 'confirmationRequired' => $confirmationRequired];
    }

    /** @param array<string,mixed> $params */
    private static function clientId(array $params): int
    {
        $clients = is_array($params['clientsdetails'] ?? null) ? $params['clientsdetails'] : [];
        foreach ([$params['userid'] ?? null, $params['clientid'] ?? null, $clients['id'] ?? null] as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 0;
    }
}
