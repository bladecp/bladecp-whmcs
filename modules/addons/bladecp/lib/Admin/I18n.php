<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Lightweight, fail-soft translations for the BladeCP addon administration.
 *
 * WHMCS provides an addon language array to bladecp_output(). The regular
 * addon language files expose the small `bladecp_locale` marker consumed here;
 * the actual UI catalogue is split into small PHP arrays under
 * lang/admin/<locale>/. Keeping this separate lets views use stable named
 * keys without coupling them to WHMCS' global language catalogue.
 */
final class I18n
{
    private const DEFAULT_LOCALE = 'english';

    /** @var array<string,string> */
    private const LOCALE_ALIASES = [
        'en' => 'english',
        'en-us' => 'english',
        'en-gb' => 'english',
        'english' => 'english',
        'pt' => 'portuguese-br',
        'pt-br' => 'portuguese-br',
        'portuguese' => 'portuguese-br',
        'portuguese-br' => 'portuguese-br',
        // WHMCS installations name their Portuguese packs differently.
        'pt-pt' => 'portuguese-br',
        'portuguese-pt' => 'portuguese-br',
        'portugues' => 'portuguese-br',
        'portugues-br' => 'portuguese-br',
        'es' => 'spanish',
        'es-es' => 'spanish',
        'es-419' => 'spanish',
        'spanish' => 'spanish',
    ];

    private static string $locale = self::DEFAULT_LOCALE;

    /** @var array<string,string> */
    private static array $catalogue = [];

    /**
     * Starts a request-local catalogue.
     *
     * @param array<string,mixed> $lang WHMCS' $vars['_lang'] value
     */
    public static function boot(array $lang): void
    {
        self::$locale = self::normaliseLocale(
            (string) (
                $lang['bladecp_locale']
                ?? $lang['locale']
                ?? $lang['language']
                ?? self::DEFAULT_LOCALE
            )
        );
        self::$catalogue = self::loadCatalogue(self::$locale);
    }

    /**
     * Returns a translated named key. An absent translated key falls back to
     * English, then to the key itself so an administrative screen never fails.
     *
     * @param array<string,scalar|null> $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        return self::format(self::$catalogue[$key] ?? self::englishCatalogue()[$key] ?? $key, $replace);
    }

    /**
     * A named key in the installation default language. WHMCS calls the addon
     * activation, deactivation and configuration callbacks without the
     * administrator language, so those messages follow the system language.
     *
     * @param array<string,scalar|null> $replace
     */
    public static function system(string $key, array $replace = []): string
    {
        $language = $GLOBALS['CONFIG']['Language'] ?? self::DEFAULT_LOCALE;
        $catalogue = self::loadCatalogue(self::normaliseLocale(is_string($language) ? $language : self::DEFAULT_LOCALE));

        return self::format($catalogue[$key] ?? self::englishCatalogue()[$key] ?? $key, $replace);
    }

    /** @param array<string,scalar|null> $replace */
    private static function format(string $value, array $replace): string
    {
        if ($replace === []) {
            return $value;
        }

        $tokens = [];
        foreach ($replace as $name => $replacement) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if ($replacement !== null && !is_scalar($replacement)) {
                continue;
            }
            $tokens['{' . $name . '}'] = match ($replacement) {
                null => '',
                true => '1',
                false => '0',
                default => (string) $replacement,
            };
        }

        return strtr($value, $tokens);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * Exposed for deterministic tests and catalogue parity checks.
     *
     * @return array<string,string>
     */
    public static function catalogue(string $locale): array
    {
        return self::loadCatalogue(self::normaliseLocale($locale));
    }

    /** @return list<string> */
    public static function locales(): array
    {
        return ['english', 'portuguese-br', 'spanish'];
    }

    private static function normaliseLocale(string $locale): string
    {
        $normalised = strtolower(str_replace('_', '-', trim($locale)));

        return self::LOCALE_ALIASES[$normalised] ?? self::DEFAULT_LOCALE;
    }

    /** @return array<string,string> */
    private static function englishCatalogue(): array
    {
        return self::loadCatalogue(self::DEFAULT_LOCALE);
    }

    /** @return array<string,string> */
    private static function loadCatalogue(string $locale): array
    {
        $catalogue = [];
        $directory = dirname(__DIR__, 2) . '/lang/admin/' . $locale;
        $files = glob($directory . '/*.php') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            try {
                $entries = require $file;
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $catalogue[$key] = $value;
                }
            }
        }

        return $catalogue;
    }
}
