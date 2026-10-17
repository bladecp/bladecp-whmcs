<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Normalizes the deliberately small public partner-branding contract. */
final class PartnerBranding
{
    /**
     * @param array<string,mixed> $data
     * @return array{publicName:string,logoUrl:string,logoDarkUrl:string}
     */
    public static function normalize(array $data): array
    {
        return [
            'publicName' => self::text($data['publicName'] ?? null, 160),
            'logoUrl' => self::url($data['logoUrl'] ?? null),
            'logoDarkUrl' => self::url($data['logoDarkUrl'] ?? null),
        ];
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= ($max * 4) ? $value : '';
    }

    private static function url(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 2048 ? $value : '';
    }
}
