<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Config;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * BladeCP platform environments known to this installation.
 *
 * The distributed package knows production only. An installation may declare
 * more environments in the optional `Environments.local.php` beside this
 * file, which returns a list of definitions with the same fields as the
 * production definition below. Every destination derived from an
 * environment (key prefix, sign-in, support, Vibe and partner logo hosts) is
 * an exact allowlist entry, so an unknown API host never gains one.
 */
final class Environments
{
    public const PRODUCTION = 'production';

    private const LOCAL_FILE = 'Environments.local.php';

    /** @var list<string> */
    private const HOST_FIELDS = ['apiHost', 'secureHost', 'supportHost', 'vibeHost', 'assetHost'];

    /**
     * Production. Its display label is translated by the administration UI.
     *
     * @var array{id:string,keyPrefix:string,label:string,apiHost:string,secureHost:string,supportHost:string,vibeHost:string,assetHost:string}
     */
    private const PRODUCTION_DEFINITION = [
        'id' => self::PRODUCTION,
        'keyPrefix' => 'live',
        'label' => '',
        'apiHost' => 'api.bladecp.com',
        'secureHost' => 'secure.bladecp.com',
        'supportHost' => 'hub.bladecp.com',
        'vibeHost' => 'bladecp.app',
        'assetHost' => 'assets.bladecp.com',
    ];

    /** @var list<array<string,string>>|null */
    private static ?array $all = null;

    /**
     * Production first, then each valid additional environment.
     *
     * @return list<array{id:string,keyPrefix:string,label:string,apiHost:string,secureHost:string,supportHost:string,vibeHost:string,assetHost:string}>
     */
    public static function all(): array
    {
        return self::$all ??= self::fromDefinitions(self::localDefinitions());
    }

    /** @return array{id:string,keyPrefix:string,label:string,apiHost:string,secureHost:string,supportHost:string,vibeHost:string,assetHost:string} */
    public static function production(): array
    {
        return self::PRODUCTION_DEFINITION;
    }

    /** @return list<array{id:string,keyPrefix:string,label:string,apiHost:string,secureHost:string,supportHost:string,vibeHost:string,assetHost:string}> */
    public static function additional(): array
    {
        return array_slice(self::all(), 1);
    }

    /** @param array<string,string> $environment */
    public static function isProduction(array $environment): bool
    {
        return ($environment['id'] ?? null) === self::PRODUCTION;
    }

    /** @return array<string,string>|null */
    public static function byKeyPrefix(string $prefix): ?array
    {
        foreach (self::all() as $environment) {
            if ($environment['keyPrefix'] === $prefix) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * Exact, case-sensitive match. Callers normalize the host the same way
     * they always did before the lookup.
     *
     * @return array<string,string>|null
     */
    public static function byApiHost(string $host): ?array
    {
        foreach (self::all() as $environment) {
            if ($environment['apiHost'] === $host) {
                return $environment;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function secureHosts(): array
    {
        return array_column(self::all(), 'secureHost');
    }

    /** @return list<string> */
    public static function assetHosts(): array
    {
        return array_column(self::all(), 'assetHost');
    }

    /**
     * Validates additional definitions. Pure and deterministic: a malformed
     * definition, or one that reuses an identifier, key prefix or host
     * already known, is dropped and never repaired.
     *
     * @return list<array{id:string,keyPrefix:string,label:string,apiHost:string,secureHost:string,supportHost:string,vibeHost:string,assetHost:string}>
     */
    public static function fromDefinitions(mixed $definitions): array
    {
        $all = [self::PRODUCTION_DEFINITION];
        if (!is_array($definitions) || !array_is_list($definitions)) {
            return $all;
        }
        foreach ($definitions as $definition) {
            $environment = self::definition($definition);
            if ($environment !== null && !self::conflicts($environment, $all)) {
                $all[] = $environment;
            }
        }

        return $all;
    }

    /** @return array<string,string>|null */
    private static function definition(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = $raw['id'] ?? null;
        $prefix = $raw['keyPrefix'] ?? null;
        $label = $raw['label'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $id) !== 1
            || !is_string($prefix) || preg_match('/^[a-z]{2,16}$/D', $prefix) !== 1
            || !is_string($label) || trim($label) === '' || strlen($label) > 64
            || preg_match('/[\x00-\x1F\x7F<>&"\']/', $label) === 1) {
            return null;
        }
        $environment = ['id' => $id, 'keyPrefix' => $prefix, 'label' => trim($label)];
        foreach (self::HOST_FIELDS as $field) {
            $host = $raw[$field] ?? null;
            if (!is_string($host) || strlen($host) > 253
                || preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) !== 1) {
                return null;
            }
            $environment[$field] = $host;
        }

        return $environment;
    }

    /**
     * @param array<string,string> $candidate
     * @param list<array<string,string>> $known
     */
    private static function conflicts(array $candidate, array $known): bool
    {
        foreach ($known as $environment) {
            if ($environment['id'] === $candidate['id'] || $environment['keyPrefix'] === $candidate['keyPrefix']) {
                return true;
            }
            $hosts = array_intersect_key($environment, array_flip(self::HOST_FIELDS));
            foreach (self::HOST_FIELDS as $field) {
                if (in_array($candidate[$field], $hosts, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function localDefinitions(): mixed
    {
        $path = __DIR__ . '/' . self::LOCAL_FILE;
        if (!is_file($path)) {
            return [];
        }
        try {
            return (static fn (string $file): mixed => require $file)($path);
        } catch (\Throwable) {
            return [];
        }
    }
}
