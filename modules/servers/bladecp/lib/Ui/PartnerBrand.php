<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Config\Environments;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Resolves a partner's public visual identity from the Customer API. It is
 * intentionally fail-soft: a branding outage must never block a customer
 * handoff. URLs are allow-listed again at this display boundary.
 */
final class PartnerBrand
{
    private const CACHE_KEY = 'partner-branding-assets-v1.json';
    private const CACHE_TTL_SECONDS = 300;
    private const STALE_IF_ERROR_SECONDS = 86400;

    /**
     * @return array{publicName:string,logoUrl:string,logoDarkUrl:string}
     */
    public static function fromClient(?Client $client): array
    {
        if ($client === null) {
            return self::empty();
        }
        $cached = CacheStore::read($client, self::CACHE_KEY, self::CACHE_TTL_SECONDS);
        if ($cached !== null) {
            return self::fromPayload($cached);
        }

        try {
            $brand = self::fromPayload($client->partnerBrandingAssets());
            CacheStore::write($client, self::CACHE_KEY, $brand);

            return $brand;
        } catch (\Throwable) {
            $stale = CacheStore::readStale(
                $client,
                self::CACHE_KEY,
                self::STALE_IF_ERROR_SECONDS
            );
            if ($stale !== null) {
                return self::fromPayload($stale);
            }
            return self::empty();
        }
    }

    /**
     * Public for deterministic module tests. Input is API data or cached data,
     * never browser data.
     *
     * @param array<string,mixed> $data
     * @return array{publicName:string,logoUrl:string,logoDarkUrl:string}
     */
    public static function fromPayload(array $data): array
    {
        $name = self::name($data['publicName'] ?? null);
        $light = self::cdnUrl($data['logoUrl'] ?? null);
        $dark = self::cdnUrl($data['logoDarkUrl'] ?? null);
        if ($light === '' && $dark !== '') {
            $light = $dark;
        }

        return [
            'publicName' => $name,
            'logoUrl' => $light,
            'logoDarkUrl' => $dark !== '' ? $dark : $light,
        ];
    }

    /** @return array{publicName:string,logoUrl:string,logoDarkUrl:string} */
    public static function empty(): array
    {
        return ['publicName' => '', 'logoUrl' => '', 'logoDarkUrl' => ''];
    }

    /**
     * HTML is assembled only from a validated HTTPS CDN URL and escaped text.
     * No SVG markup is ever injected into a WHMCS template.
     */
    public static function logoHtml(array $brand, int $height = 32): string
    {
        $height = max(18, min(56, $height));
        $light = self::cdnUrl($brand['logoUrl'] ?? null);
        $dark = self::cdnUrl($brand['logoDarkUrl'] ?? null);
        if ($light === '') {
            return '<span class="bladecp-partner-brand bladecp-partner-brand--fallback"'
                . ' style="font-size:' . $height . 'px">BladeCP</span>';
        }
        $dark = $dark !== '' ? $dark : $light;
        $name = self::name($brand['publicName'] ?? null);
        $escape = static fn (string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $alt = $name !== '' ? $name : 'Parceiro BladeCP';

        return '<span class="bladecp-partner-brand" aria-label="'
            . $escape($alt) . '"><img class="bladecp-partner-brand__light"'
            . ' src="' . $escape($light) . '" alt="' . $escape($alt)
            . '" height="' . $height . '"><img class="bladecp-partner-brand__dark"'
            . ' src="' . $escape($dark) . '" alt="" aria-hidden="true"'
            . ' height="' . $height . '"></span>';
    }

    private static function name(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 640
            ? $value : '';
    }

    private static function cdnUrl(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return '';
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $path = (string) ($parts['path'] ?? '');
        if (!in_array($host, Environments::assetHosts(), true)
            || $path === '' || !str_starts_with($path, '/') || str_contains($path, '//')) {
            return '';
        }

        return $value;
    }
}
