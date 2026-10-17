<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Resolve o id numérico do catálogo para o UUID exigido pelo Vibe. */
final class VibePlanReference
{
    public static function fromParams(array $params): ?string
    {
        $options = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];
        $reference = trim((string) (
            $options['BladeCP Plan'] ?? $params['configoption2'] ?? ''
        ));
        if (self::isUuid($reference)) {
            return $reference;
        }
        if ($reference === '') {
            return null;
        }

        try {
            return self::fromCatalog(
                $reference,
                Client::fromParams($params)->get('/partner/plans')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public static function fromCatalog(string $reference, mixed $response): ?string
    {
        $rows = is_array($response) && isset($response['data'])
            ? $response['data']
            : $response;
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $row) {
            if (
                !is_array($row)
                || (string) ($row['id'] ?? '') !== $reference
                || !is_array($row['vibe'] ?? null)
            ) {
                continue;
            }
            $uuid = trim((string) ($row['uuid'] ?? ''));

            return self::isUuid($uuid) ? $uuid : null;
        }

        return null;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
