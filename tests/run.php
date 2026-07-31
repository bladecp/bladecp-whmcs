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

check('public package version is complete and matches module metadata', static function () use ($serverRoot, $moduleVersion, $moduleRevision): void {
    $metadata = json_decode((string) file_get_contents($serverRoot . '/whmcs.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(is_string($moduleVersion) && $moduleVersion !== '', 'module version is missing');
    expect(($metadata['version'] ?? null) === $moduleVersion, 'module metadata version does not match');
    expect(is_string($moduleRevision) && preg_match('/^[0-9a-f]{7,40}$/', $moduleRevision) === 1, 'public revision was not materialized');
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

check('Vibe stays recognized but is never provisioned by this module', static function (): void {
    expect(ProductType::getFromParams(['configoption1' => ProductType::VIBE]) === ProductType::VIBE, 'Vibe is no longer recognized');
    try {
        ProductCapability::handlerClass(ProductType::VIBE, 'CreateAccount');
    } catch (RuntimeException $error) {
        expect(str_contains($error->getMessage(), 'vibe'), 'Vibe rejection is not explicit');
        return;
    }
    throw new RuntimeException('Vibe unexpectedly returned a provisioning handler');
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
        'CHANGELOG.md',
        'SECURITY.md',
        'modules/servers/bladecp/bladecp.php',
        'modules/addons/bladecp/bladecp.php',
        'modules/addons/bladecp/webhook.php',
    ] as $path) {
        expect(is_file($root . '/' . $path), "missing {$path}");
    }
});

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " public checks failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\n{$checks} public checks passed.\n";
