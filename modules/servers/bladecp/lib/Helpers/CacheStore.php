<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Pequeno armazenamento de cache local para dados derivados da Customer
 * API. Os caminhos nunca incluem host, chave ou id de servidor em texto:
 * cada escopo combina a instalação, endpoint e um fingerprint irreversível
 * da credencial. Assim um cache de outro ambiente ou de outra conta não é
 * reutilizado por engano.
 *
 * Best-effort por desenho: qualquer falha de I/O devolve cache miss e quem
 * chama continua seu fluxo normal de rede/fail-soft.
 */
final class CacheStore
{
    private const ROOT = 'bladecp-whmcs-cache-v1';

    /**
     * Lê JSON válido dentro do TTL. O valor salvo deve ser array para manter
     * os consumidores atuais simples e defensivos.
     *
     * @return array<mixed>|null
     */
    public static function read(Client $client, string $name, int $ttl): ?array
    {
        return self::readWithinAge($client, $name, $ttl);
    }

    /**
     * Reads a cache entry beyond its fresh TTL for explicitly fail-soft
     * consumers. Callers must use this only after a network failure.
     *
     * @return array<mixed>|null
     */
    public static function readStale(Client $client, string $name, int $maxAge): ?array
    {
        return self::readWithinAge($client, $name, $maxAge);
    }

    /** @return array<mixed>|null */
    private static function readWithinAge(Client $client, string $name, int $maxAge): ?array
    {
        $path = self::path($client, $name);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        if ($mtime === false || $maxAge < 0 || time() - $mtime > $maxAge) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<mixed> $value */
    public static function write(Client $client, string $name, array $value): void
    {
        $path = self::path($client, $name);
        if ($path === null) {
            return;
        }
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            $directory = dirname($path);
            $temporary = @tempnam($directory, '.write-');
            if (!is_string($temporary) || $temporary === '') {
                return;
            }
            try {
                if (@chmod($temporary, 0600) === false) {
                    return;
                }
                if (@file_put_contents($temporary, $encoded, LOCK_EX) === false) {
                    return;
                }
                if (!@rename($temporary, $path)) {
                    return;
                }
                @chmod($path, 0600);
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        } catch (\Throwable) {
            // Cache é uma otimização, nunca uma dependência do módulo.
        }
    }

    private static function path(Client $client, string $name): ?string
    {
        $safeName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?? '';
        if ($safeName === '') {
            return null;
        }
        $root = self::root();
        if ($root === null) {
            return null;
        }
        $scope = hash('sha256', implode("\0", [
            self::installationFingerprint(),
            $client->baseUrl(),
            self::credentialFingerprint($client),
        ]));
        $directory = $root . '/' . $scope;
        if (!self::privateDirectory($directory)) {
            return null;
        }

        return $directory . '/' . $safeName;
    }

    private static function root(): ?string
    {
        $temporary = trim((string) sys_get_temp_dir());
        if ($temporary === '') {
            return null;
        }
        $root = rtrim($temporary, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::ROOT;

        return self::privateDirectory($root) ? $root : null;
    }

    private static function privateDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return false;
        }

        return @chmod($directory, 0700);
    }

    private static function installationFingerprint(): string
    {
        $root = defined('ROOTDIR') ? (string) constant('ROOTDIR') : '';
        if ($root === '' && isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
            $root = $_SERVER['DOCUMENT_ROOT'];
        }
        if ($root === '') {
            $root = dirname(__DIR__, 4);
        }
        $real = @realpath($root);

        return $real !== false ? $real : $root;
    }

    private static function credentialFingerprint(Client $client): string
    {
        try {
            $reader = \Closure::bind(
                static fn (Client $source): string => $source->apiKey,
                null,
                Client::class
            );
            $key = is_callable($reader) ? $reader($client) : '';

            return hash('sha256', is_string($key) ? $key : '');
        } catch (\Throwable) {
            // Sem acesso ao fingerprint não é seguro compartilhar cache.
            return hash('sha256', 'unavailable:' . spl_object_id($client));
        }
    }
}
