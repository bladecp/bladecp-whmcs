<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Catálogo de componentes disponíveis para produtos BladeCP.
 *
 * A consulta é opcional: quando o catálogo não estiver disponível, a
 * operação continua e a API valida a solicitação.
 */
final class Catalog
{
    private const CACHE_TTL = 3600;
    private const CACHE_FILE = 'bladecp_components_catalog.json';
    // Mantém separado o cache de detalhes do cache de slugs.
    private const CACHE_FILE_FULL = 'bladecp_components_catalog_full_v5.json';

    /** @return string[]|null */
    public static function slugsFor(Client $client): ?array
    {
        $cached = self::readCache($client);
        if ($cached !== null) {
            return $cached;
        }
        try {
            $slugs = self::parse($client->get('/components/catalog'));
        } catch (\Throwable) {
            return null;
        }
        self::writeCache($client, $slugs);
        return $slugs;
    }

    /** @return array<int,array{slug:string,name:string,category:string,featured:bool}>|null */
    public static function catalog(Client $client): ?array
    {
        $cached = self::readCacheFull($client);
        if ($cached !== null) {
            return $cached;
        }
        try {
            $rows = self::parseCatalog($client->get('/components/catalog'));
        } catch (\Throwable) {
            return null;
        }
        self::writeCacheFull($client, $rows);
        return $rows;
    }

    /** @return array<int,array{slug:string,name:string,category:string,featured:bool,versions:array<int,array{id:int,version:int,is_default:bool}>}> */
    public static function parseCatalog(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($response as $row) {
            if (!is_array($row)) {
                continue;
            }
            $slug = isset($row['slug']) && is_string($row['slug']) ? $row['slug'] : '';
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $name = trim((string) ($row['name'] ?? ''));
            $cat = trim((string) ($row['category'] ?? ''));
            $out[] = [
                'slug' => $slug,
                'name' => $name !== '' ? $name : $slug,
                'category' => $cat !== '' ? $cat : 'Outros',
                'featured' => !empty($row['featured']),
                'versions' => self::parseVersions($row['versions'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array<int,array{id:int,version:int,image:string,label:string,is_default:bool}> */
    public static function parseVersions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $v) {
            if (!is_array($v) || !isset($v['id']) || !is_numeric($v['id'])) {
                continue;
            }
            $id = (int) $v['id'];
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            if (!isset($v['version']) || !is_numeric($v['version'])) {
                continue;
            }
            $seen[$id] = true;
            $ver = (int) $v['version'];
            $image = trim((string) ($v['image'] ?? ''));
            $out[] = [
                'id' => $id,
                'version' => $ver,
                'image' => $image,
                'label' => self::versionLabel($image, $ver),
                'is_default' => !empty($v['is_default']),
            ];
        }
        return $out;
    }

    /** Produz um rótulo de versão legível a partir da imagem do componente. */
    public static function versionLabel(string $image, int $version): string
    {
        $image = trim($image);
        $pos = strrpos($image, ':');
        $tag = $pos !== false ? trim(substr($image, $pos + 1)) : '';
        // Remove sufixos de distribuição para exibir uma versão concisa.
        $dash = strpos($tag, '-');
        if ($dash !== false) {
            $tag = trim(substr($tag, 0, $dash));
        }
        if ($tag === '' || strtolower($tag) === 'latest') {
            return 'v' . $version;
        }
        return $tag;
    }

    /** @return string[] */
    public static function parse(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response)) {
            return [];
        }
        $slugs = [];
        foreach ($response as $row) {
            if (is_array($row) && isset($row['slug']) && is_string($row['slug']) && $row['slug'] !== '') {
                $slugs[] = $row['slug'];
            }
        }
        return array_values(array_unique($slugs));
    }

    /** @param string[] $selected @param string[] $catalogSlugs @return string[] */
    public static function unknown(array $selected, array $catalogSlugs): array
    {
        $known = array_flip($catalogSlugs);
        $bad = [];
        foreach ($selected as $slug) {
            if (!isset($known[$slug])) {
                $bad[] = $slug;
            }
        }
        return array_values(array_unique($bad));
    }

    /** @return string[]|null */
    private static function readCache(Client $client): ?array
    {
        $decoded = CacheStore::read($client, self::CACHE_FILE, self::CACHE_TTL);
        if (!is_array($decoded)) {
            return null;
        }
        return array_values(array_filter(
            $decoded,
            static fn ($v) => is_string($v) && $v !== ''
        ));
    }

    /** @param string[] $slugs */
    private static function writeCache(Client $client, array $slugs): void
    {
        CacheStore::write($client, self::CACHE_FILE, array_values($slugs));
    }

    /** @return array<int,array{slug:string,name:string,category:string,featured:bool}>|null */
    private static function readCacheFull(Client $client): ?array
    {
        $decoded = CacheStore::read($client, self::CACHE_FILE_FULL, self::CACHE_TTL);
        if (!is_array($decoded)) {
            return null;
        }
        // Normaliza o conteúdo lido do cache antes de usá-lo.
        return self::parseCatalog($decoded);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function writeCacheFull(Client $client, array $rows): void
    {
        CacheStore::write($client, self::CACHE_FILE_FULL, array_values($rows));
    }
}
