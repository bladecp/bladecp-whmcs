<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Resolves the effective locale for client-area UI rendered by the module.
 *
 * A WHMCS client can temporarily change their active language without changing
 * the language persisted on the client profile. The current session therefore
 * has precedence over module params and the stored client preference.
 */
final class ClientLocale
{
    /**
     * Resolve a supported client locale.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed>|null $session
     */
    public static function resolve(array $params, ?array $session = null): string
    {
        $session ??= [];

        $value = self::firstString([
            $session['Language'] ?? null,
            $session['language'] ?? null,
            $params['language'] ?? null,
            is_array($params['clientsdetails'] ?? null)
                ? ($params['clientsdetails']['language'] ?? null)
                : null,
        ]);

        return self::normalize($value);
    }

    /** Normalize a WHMCS language slug to the client panel's supported locales. */
    public static function normalize(string $locale): string
    {
        $value = strtolower(trim($locale));

        return match ($value) {
            'portuguese', 'portuguese-br', 'portuguese_br', 'pt', 'pt-br', 'pt_br' => 'pt-BR',
            'spanish', 'es', 'es-es', 'es_es', 'es-419', 'es_419' => 'es',
            'english', 'en', 'en-us', 'en_us', 'en-gb', 'en_gb' => 'en',
            default => 'en',
        };
    }

    /** @param list<mixed> $values */
    private static function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
