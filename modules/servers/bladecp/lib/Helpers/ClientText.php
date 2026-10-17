<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Customer-facing messages of the server module, in the customer's language.
 *
 * The locale follows ClientLocale: the current WHMCS session first, then the
 * language passed to the module call and the client profile, then the
 * installation default. Messages live in lang/client/<locale>.php, and a key
 * missing from a translation falls back to English.
 */
final class ClientText
{
    /** @var array<string,string> */
    private const FILES = ['en' => 'english', 'pt-BR' => 'portuguese-br', 'es' => 'spanish'];

    /** @var array<string,array<string,string>> */
    private static array $cache = [];

    /** @param array<string,mixed> $params */
    public static function locale(array $params = []): string
    {
        $session = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
        $candidates = [
            $session['Language'] ?? null,
            $session['language'] ?? null,
            $params['language'] ?? null,
            is_array($params['clientsdetails'] ?? null) ? ($params['clientsdetails']['language'] ?? null) : null,
            $GLOBALS['CONFIG']['Language'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return ClientLocale::normalize($value);
            }
        }

        return 'en';
    }

    /**
     * @param array<string,scalar|null> $replace
     * @param array<string,mixed> $params
     */
    public static function get(string $key, array $replace = [], ?string $locale = null, array $params = []): string
    {
        $locale = $locale !== null ? ClientLocale::normalize($locale) : self::locale($params);
        $value = self::table($locale)[$key] ?? self::table('en')[$key] ?? $key;
        $tokens = [];
        foreach ($replace as $name => $replacement) {
            if (is_string($name) && $name !== '' && ($replacement === null || is_scalar($replacement))) {
                $tokens['{' . $name . '}'] = (string) $replacement;
            }
        }

        return $tokens === [] ? $value : strtr($value, $tokens);
    }

    /** @return array<string,string> */
    public static function table(string $locale): array
    {
        $file = self::FILES[ClientLocale::normalize($locale)] ?? 'english';
        if (!isset(self::$cache[$file])) {
            $entries = require dirname(__DIR__, 2) . '/lang/client/' . $file . '.php';
            self::$cache[$file] = array_filter(
                is_array($entries) ? $entries : [],
                static fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return self::$cache[$file];
    }
}
