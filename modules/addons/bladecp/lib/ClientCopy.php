<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Client area copy in the customer's language.
 *
 * Same resolution as the other client surfaces of the addon (Fast Deploy,
 * Vibe prompt card, account confirmation): the language of the current WHMCS
 * session, then the installation default, then English. Each domain is a PHP
 * array under lang/<domain>/<locale>.php, and a key missing from a translation
 * falls back to English so a page never shows an internal key.
 */
final class ClientCopy
{
    /** @var list<string> */
    public const DOMAINS = ['wordpress', 'client-flows'];

    /** @var list<string> */
    public const LOCALES = ['english', 'portuguese-br', 'spanish'];

    /** @var array<string,array<string,string>> */
    private static array $cache = [];

    public static function locale(?string $language = null): string
    {
        if ($language === null) {
            $session = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
            $language = is_string($session['Language'] ?? null) && trim($session['Language']) !== ''
                ? $session['Language']
                : (is_string($GLOBALS['CONFIG']['Language'] ?? null) ? $GLOBALS['CONFIG']['Language'] : 'english');
        }

        return match (strtolower(str_replace('_', '-', trim($language)))) {
            'pt', 'pt-br', 'portuguese', 'portuguese-br' => 'portuguese-br',
            'es', 'es-es', 'es-419', 'spanish' => 'spanish',
            default => 'english',
        };
    }

    /** @return array<string,string> */
    public static function domain(string $domain, ?string $language = null): array
    {
        $english = self::load($domain, 'english');
        $locale = self::locale($language);

        return $locale === 'english' ? $english : self::load($domain, $locale) + $english;
    }

    /** @param array<string,scalar|null> $replace */
    public static function text(string $domain, string $key, array $replace = [], ?string $language = null): string
    {
        $value = self::domain($domain, $language)[$key] ?? $key;
        $tokens = [];
        foreach ($replace as $name => $replacement) {
            if (is_string($name) && $name !== '' && ($replacement === null || is_scalar($replacement))) {
                $tokens['{' . $name . '}'] = (string) $replacement;
            }
        }

        return $tokens === [] ? $value : strtr($value, $tokens);
    }

    /** @return array<string,string> */
    private static function load(string $domain, string $locale): array
    {
        if (!in_array($domain, self::DOMAINS, true) || !in_array($locale, self::LOCALES, true)) {
            throw new \InvalidArgumentException('Unknown client copy.');
        }
        $key = $domain . '/' . $locale;
        if (!isset(self::$cache[$key])) {
            $entries = require dirname(__DIR__) . '/lang/' . $domain . '/' . $locale . '.php';
            self::$cache[$key] = array_filter(
                is_array($entries) ? $entries : [],
                static fn (mixed $value, mixed $name): bool => is_string($name) && is_string($value),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return self::$cache[$key];
    }
}
