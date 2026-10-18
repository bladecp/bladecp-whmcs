<?php

declare(strict_types=1);

/**
 * Public, dependency-free regression checks for the distributed package.
 *
 * Run from the exported repository root with: php tests/run.php
 */

define('WHMCS', true);

$root = dirname(__DIR__);
$serverRoot = $root . '/modules/servers/bladecp';
$addonRoot = $root . '/modules/addons/bladecp';

foreach ([$serverRoot . '/bootstrap.php', $serverRoot . '/whmcs.json', $serverRoot . '/moduleVersion.php'] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "Missing required public package file: {$required}\n");
        exit(1);
    }
}

require_once $serverRoot . '/bootstrap.php';
\WHMCS\Module\Server\Bladecp\Bootstrap::registerAddonAutoloaders($addonRoot, $serverRoot);
require $serverRoot . '/moduleVersion.php';

$checks = 0;
$failures = [];

/** @param callable():void $test */
function check(string $name, callable $test): void
{
    global $checks, $failures;
    $checks++;

    try {
        $test();
        echo "ok {$checks} - {$name}\n";
    } catch (Throwable $error) {
        $failures[] = "{$name}: {$error->getMessage()}";
        echo "not ok {$checks} - {$name}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

use WHMCS\Module\Addon\Bladecp\WebhookReceiver;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ParamsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ProductCapability;
use WHMCS\Module\Server\Bladecp\Helpers\ProductType;

check('public package version and distribution metadata are complete', static function () use ($serverRoot, $moduleVersion, $moduleRevision, $distributionChannel, $publicReleaseTag): void {
    $metadata = json_decode((string) file_get_contents($serverRoot . '/whmcs.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(is_string($moduleVersion) && $moduleVersion !== '', 'module version is missing');
    expect(($metadata['version'] ?? null) === $moduleVersion, 'module metadata version does not match');
    expect($publicReleaseTag === 'v' . $moduleVersion, 'public release tag was not materialized');
    expect(in_array($distributionChannel, ['prerelease', 'stable'], true), 'public distribution channel is invalid');
    expect($moduleRevision === null, 'development revision must not be exposed');
});

check('About metadata exposes public release data without private provenance', static function (): void {
    $metadata = \WHMCS\Module\Addon\Bladecp\About::metadata();
    expect(($metadata['releaseTag'] ?? null) === 'v' . ($metadata['version'] ?? ''), 'About release tag does not match version');
    expect(in_array($metadata['channel'] ?? null, ['prerelease', 'stable'], true), 'About channel is not public');
    expect(!array_key_exists('revision', $metadata), 'About metadata exposed a source revision');
});

check('supported product types resolve to their own handlers', static function (): void {
    $expectations = [
        ProductType::STACK_CONTAINER => 'Stack',
        ProductType::EDGE_CLOUD => 'Edge',
        ProductType::DEDICATED => 'Dedicated',
    ];
    foreach ($expectations as $type => $namespace) {
        expect(ProductType::getFromParams(['configoption1' => $type]) === $type, "{$type} was not resolved");
        $handler = ProductCapability::handlerClass($type, 'CreateAccount');
        expect(str_contains($handler, "\\Functions\\{$namespace}\\CreateAccount"), "{$type} resolved to the wrong handler");
    }
});

check('Vibe resolves only to its dedicated account handlers', static function (): void {
    expect(ProductType::getFromParams(['configoption1' => ProductType::VIBE]) === ProductType::VIBE, 'Vibe is no longer recognized');
    $handler = ProductCapability::handlerClass(ProductType::VIBE, 'CreateAccount');
    expect(str_contains($handler, '\\Functions\\Vibe\\CreateAccount'), 'Vibe did not resolve to its dedicated handler');
});

check('webhook signatures require the exact signed body and timestamp', static function (): void {
    $timestamp = 1_700_000_000;
    $secret = 'public-test-webhook-secret';
    $payload = '{"type":"provision.active"}';
    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    $header = "t={$timestamp},v1={$signature}";

    expect(WebhookReceiver::verifySignature($payload, $header, $secret, $timestamp), 'valid webhook signature was rejected');
    expect(!WebhookReceiver::verifySignature($payload . 'x', $header, $secret, $timestamp), 'modified webhook body was accepted');
    expect(!WebhookReceiver::verifySignature($payload, $header, $secret, $timestamp + 301), 'expired webhook was accepted');
});

check('credential-like values are redacted before they reach logs or messages', static function (): void {
    $apiKey = 'bcp_' . 'live_' . 'abcdefghijklmnop';
    $webhookSecret = 'whsec_' . 'abcdefghijklmnop';
    $redacted = Logger::redact("Authorization: Bearer {$apiKey} token=topsecret {$webhookSecret}");
    expect(!str_contains($redacted, $apiKey), 'Customer API key leaked');
    expect(!str_contains($redacted, 'topsecret'), 'token leaked');
    expect(!str_contains($redacted, $webhookSecret), 'webhook secret leaked');
    expect(substr_count($redacted, '***REDACTED***') >= 3, 'expected redactions were not applied');
});

check('single sign-on redirects accept only absolute HTTPS URLs', static function (): void {
    expect(ParamsMapper::isAbsoluteHttpsUrl('https://panel.bladecp.com/session/abc'), 'valid HTTPS URL was rejected');
    foreach (['http://panel.bladecp.com', '/session/abc', 'javascript:alert(1)', 'https:///missing-host', ''] as $invalid) {
        expect(!ParamsMapper::isAbsoluteHttpsUrl($invalid), "unsafe SSO URL was accepted: {$invalid}");
    }
});

check('public package keeps the expected installable module layout', static function () use ($root): void {
    foreach ([
        'LICENSE',
        'README.md',
        'README.pt-BR.md',
        'README.es.md',
        'CHANGELOG.md',
        'SECURITY.md',
        'assets/screenshots/README.md',
        'modules/servers/bladecp/bladecp.php',
        'modules/addons/bladecp/bladecp.php',
        'modules/addons/bladecp/webhook.php',
    ] as $path) {
        expect(is_file($root . '/' . $path), "missing {$path}");
    }
});

check('public documentation declares three localized READMEs and 24 localized screenshots', static function () use ($root): void {
    $languages = ['en', 'pt-BR', 'es'];
    $views = ['overview', 'configuration', 'plans', 'pricing', 'components', 'client-area', 'migrations', 'diagnostics'];
    $screenshots = [];

    foreach ($languages as $language) {
        foreach ($views as $view) {
            $path = $root . "/assets/screenshots/{$language}/{$view}.jpg";
            expect(is_file($path), "missing localized screenshot {$language}/{$view}");
            $contents = (string) file_get_contents($path);
            expect(substr($contents, 0, 3) === "\xFF\xD8\xFF", "{$language}/{$view} is not a JPEG");
            expect(strpos($contents, "Exif\x00\x00") === false, "{$language}/{$view} contains EXIF metadata");
            expect(strpos($contents, 'http://ns.adobe.com/xap/1.0/') === false, "{$language}/{$view} contains XMP metadata");
            expect(strpos($contents, 'Photoshop 3.0') === false, "{$language}/{$view} contains Photoshop metadata");
            $screenshots[] = $path;
        }
    }

    $actual = glob($root . '/assets/screenshots/*/*.jpg') ?: [];
    sort($actual);
    sort($screenshots);
    expect($actual === $screenshots, 'public package does not contain exactly the approved screenshot set');
});

check('public files name only public hosts, reserved example addresses and special-purpose IPv4 ranges', static function () use ($root): void {
    $publicHosts = [
        'bladecp.com', 'www.bladecp.com', 'api.bladecp.com', 'secure.bladecp.com', 'hub.bladecp.com',
        'panel.bladecp.com', 'assets.bladecp.com', 'whmcs-demo.bladecp.com', 'bladecp.app',
    ];
    $reservedSuffix = '/\\.(?:test|example|invalid|localhost)$/';
    $fileSuffix = '/\\.(?:php|js|cjs|mjs|css|json|md|svg|zip|tpl|png|jpg|jpeg|txt|sh|yml|yaml|html|htm|xml|lock|log|conf|ini|sql|git)$/';
    $special = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];
    $isSpecial = static function (string $address) use ($special): bool {
        $value = ip2long($address);
        foreach ($special as [$network, $bits]) {
            if ($value !== false && ($value >> (32 - $bits)) === (ip2long($network) >> (32 - $bits))) {
                return true;
            }
        }
        return false;
    };
    $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => $file->getFilename() !== '.git'
    ));
    $problems = [];
    foreach ($files as $file) {
        if (!$file->isFile() || preg_match('/\\.(?:jpe?g|png|gif|ico|webp|zip|gz|woff2?|ttf|eot|pdf)$/i', $file->getFilename()) === 1) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $contents = (string) file_get_contents($file->getPathname());
        preg_match_all('/(?<![A-Za-z0-9_$-])((?:[A-Za-z0-9-]+\\.)*[A-Za-z0-9-]*bladecp[A-Za-z0-9-]*(?:\\.[A-Za-z0-9-]+)*\\.[A-Za-z]{2,63})(?![A-Za-z0-9_-])/i', $contents, $hosts);
        foreach ($hosts[1] as $host) {
            $host = rtrim(strtolower($host), '.');
            if (!in_array($host, $publicHosts, true) && preg_match($reservedSuffix, $host) !== 1 && preg_match($fileSuffix, $host) !== 1) {
                $problems[] = "{$relative}: host {$host}";
            }
        }
        preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\\.[A-Za-z0-9-]+)+)/', $contents, $emails);
        foreach ($emails[1] as $index => $domain) {
            $domain = strtolower($domain);
            if ($domain !== 'bladecp.com' && preg_match('/(?:^|\\.)example\\.(?:com|net|org)$/', $domain) !== 1 && preg_match($reservedSuffix, $domain) !== 1) {
                $problems[] = "{$relative}: e-mail {$emails[0][$index]}";
            }
        }
        preg_match_all('/(?<![\\w.])(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3})(?!\\w|\\.\\d)/', $contents, $addresses, PREG_SET_ORDER);
        foreach ($addresses as $address) {
            if (max(array_map('intval', array_slice($address, 1))) <= 255 && !$isSpecial($address[0])) {
                $problems[] = "{$relative}: IPv4 {$address[0]}";
            }
        }
    }
    expect($problems === [], 'address outside the public rules: ' . implode('; ', array_slice($problems, 0, 5)));
});

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " public checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\n{$checks} public checks passed.\n";
