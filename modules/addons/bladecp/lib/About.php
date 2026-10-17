<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Addon\Bladecp\Admin\I18n;
use WHMCS\Module\Addon\Bladecp\Admin\Layout;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Public-safe, read-only information about the installed distribution.
 */
final class About
{
    private const WEBSITE_URL = 'https://www.bladecp.com';
    private const REPOSITORY_URL = 'https://github.com/bladecp/bladecp-whmcs';
    private const SUPPORT_EMAIL = 'dev@bladecp.com';

    /** @return array<string,string|null> */
    public static function metadata(): array
    {
        $serverRoot = dirname(__DIR__, 3) . '/servers/bladecp';
        $version = '';
        $channel = 'development';
        $releaseTag = null;
        $minimumPhp = '8.1';

        $versionFile = $serverRoot . '/moduleVersion.php';
        if (is_file($versionFile)) {
            try {
                $loaded = (static function (string $path): array {
                    $moduleVersion = '';
                    $distributionChannel = 'development';
                    $publicReleaseTag = null;
                    require $path;

                    return [
                        'version' => $moduleVersion,
                        'channel' => $distributionChannel,
                        'releaseTag' => $publicReleaseTag,
                    ];
                })($versionFile);
                $version = self::safeVersion($loaded['version'] ?? null);
                $channel = self::safeChannel($loaded['channel'] ?? null);
                $releaseTag = self::safeReleaseTag($loaded['releaseTag'] ?? null, $version);
            } catch (\Throwable) {
                // The About screen remains available with conservative defaults.
            }
        }

        $manifestFile = $serverRoot . '/whmcs.json';
        if (is_file($manifestFile)) {
            try {
                $manifest = json_decode(
                    (string) file_get_contents($manifestFile),
                    true,
                    16,
                    JSON_THROW_ON_ERROR
                );
                if (is_array($manifest)) {
                    $manifestVersion = self::safeVersion($manifest['version'] ?? null);
                    if ($version === '') {
                        $version = $manifestVersion;
                    }
                    $candidate = self::safeVersion($manifest['minPhpVersion'] ?? null);
                    if ($candidate !== '') {
                        $minimumPhp = $candidate;
                    }
                }
            } catch (\Throwable) {
                // Invalid optional metadata must not break the administration UI.
            }
        }

        if ($releaseTag === null) {
            $channel = 'development';
        }

        return [
            'name' => 'BladeCP for WHMCS',
            'version' => $version !== '' ? $version : I18n::t('about.value.unavailable'),
            'channel' => $channel,
            'releaseTag' => $releaseTag,
            'minimumPhp' => $minimumPhp,
            'currentPhp' => PHP_VERSION,
            'license' => 'MIT',
            'publisher' => 'BladeCP',
        ];
    }

    public static function render(string $base): string
    {
        $metadata = self::metadata();
        $channel = (string) $metadata['channel'];
        $isPublic = $metadata['releaseTag'] !== null;
        $channelLabel = I18n::t('about.channel.' . $channel);

        $hero = '<section class="bcp-about-hero">'
            . '<span class="bcp-eyebrow">' . self::e(I18n::t('about.eyebrow')) . '</span>'
            . '<h4>' . self::e((string) $metadata['name']) . '</h4>'
            . '<p>' . self::e(I18n::t('about.intro')) . '</p>'
            . '<div class="bcp-about-badges">'
            . '<span class="bcp-badge">' . self::e(I18n::t('about.version')) . ' '
            . self::e((string) $metadata['version']) . '</span>'
            . '<span class="bcp-badge">' . self::e($channelLabel) . '</span>'
            . '</div></section>';

        $details = '<section class="bcp-about-section"><h4>'
            . self::e(I18n::t('about.installation.title')) . '</h4>'
            . '<p>' . self::e(I18n::t('about.installation.detail')) . '</p>'
            . '<dl class="bcp-about-details">'
            . self::detail('about.version', (string) $metadata['version'])
            . self::detail('about.channel', $channelLabel)
            . ($metadata['releaseTag'] !== null
                ? self::detail('about.releaseTag', (string) $metadata['releaseTag'])
                : '')
            . self::detail('about.publisher', (string) $metadata['publisher'])
            . self::detail('about.license', (string) $metadata['license'])
            . self::detail('about.php.minimum', (string) $metadata['minimumPhp'])
            . self::detail('about.php.current', (string) $metadata['currentPhp'])
            . '</dl></section>';

        $links = '<section class="bcp-about-section"><h4>'
            . self::e(I18n::t('about.resources.title')) . '</h4>'
            . '<p>' . self::e($isPublic
                ? I18n::t('about.resources.publicDetail')
                : I18n::t('about.resources.developmentDetail')) . '</p>'
            . '<div class="bcp-about-links">'
            . self::externalLink(self::WEBSITE_URL, 'about.resources.website')
            . self::externalLink('mailto:' . self::SUPPORT_EMAIL, 'about.resources.support', false);

        if ($isPublic) {
            $links .= self::externalLink(self::REPOSITORY_URL, 'about.resources.repository')
                . self::externalLink(self::REPOSITORY_URL . '/releases', 'about.resources.releases')
                . self::externalLink(self::REPOSITORY_URL . '/blob/main/SECURITY.md', 'about.resources.security')
                . self::externalLink(self::REPOSITORY_URL . '/issues', 'about.resources.issues');
        }
        $links .= '</div></section>';

        $privacy = '<div class="bcp-help-strip"><strong>'
            . self::e(I18n::t('about.privacy.title')) . '</strong> '
            . self::e(I18n::t('about.privacy.detail')) . '</div>';

        $header = Layout::header(
            I18n::t('about.title'),
            I18n::t('about.headerDetail')
        );

        return Layout::card(
            $header,
            Layout::tabs($base, 'about') . $hero
                . '<div class="bcp-about-grid">' . $details . $links . '</div>'
                . $privacy
        );
    }

    private static function detail(string $labelKey, string $value): string
    {
        return '<div><dt>' . self::e(I18n::t($labelKey)) . '</dt><dd>'
            . self::e($value) . '</dd></div>';
    }

    private static function externalLink(
        string $url,
        string $labelKey,
        bool $newTab = true
    ): string {
        return '<a class="btn btn-default" href="' . self::e($url) . '"'
            . ($newTab ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
            . Layout::icon('external-link') . self::e(I18n::t($labelKey))
            . ($newTab ? '<span class="sr-only"> '
                . self::e(I18n::t('layout.opensInNewTab')) . '</span>' : '')
            . '</a>';
    }

    private static function safeVersion(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);

        return preg_match('/^[0-9]+(?:\.[0-9]+)+(?:[-+][0-9A-Za-z.-]+)?$/', $value) === 1
            ? $value
            : '';
    }

    private static function safeChannel(mixed $value): string
    {
        return is_string($value) && in_array($value, ['development', 'prerelease', 'stable'], true)
            ? $value
            : 'development';
    }

    private static function safeReleaseTag(mixed $value, string $version): ?string
    {
        if (!is_string($value) || $version === '') {
            return null;
        }
        $value = trim($value);

        return hash_equals('v' . $version, $value) ? $value : null;
    }

    private static function e(mixed $value): string
    {
        return Layout::escape($value);
    }
}
