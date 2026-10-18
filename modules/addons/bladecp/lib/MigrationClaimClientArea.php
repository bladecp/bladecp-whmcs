<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Addon\Bladecp\FastDeploy\BillingImport;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Helpers\MigrationClaimResume;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Account confirmation for a migration started by the account holder in
 * cPanel. The token only names the migration. The logged-in WHMCS owner is
 * the client who receives the service, after an explicit CSRF-protected
 * confirmation.
 */
final class MigrationClaimClientArea
{
    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars = []): array
    {
        self::protectResponse();
        MigrationClaimResume::rememberTokenFromRequest();
        // The token now lives in the server session only.
        unset($_GET['token']);
        $copy = self::copy();

        $identity = IdentityHandoff::currentIdentityDecision();
        if (($identity['status'] ?? '') === 'not_authenticated') {
            // WHMCS shows its login or sign up and comes back to this route.
            return self::view($copy, self::message($copy, 'login', 'info'));
        }
        if (($identity['status'] ?? '') !== 'ready' || !is_array($identity['identity'] ?? null)) {
            return self::view($copy, self::message($copy, 'owner_only'));
        }
        $clientId = (int) $identity['identity']['clientId'];
        $token = MigrationClaimResume::token();
        if ($token === null) {
            return self::view($copy, self::message($copy, 'expired'));
        }
        $verified = self::verify($token);
        if (($verified['ok'] ?? false) !== true) {
            $error = (string) ($verified['error'] ?? 'failed');
            if ($error === 'used' && MigrationClaimResume::isDone($token)) {
                MigrationClaimResume::forget();
                return self::view($copy, self::done($copy));
            }
            if ($error !== 'unavailable') {
                MigrationClaimResume::forget();
            }
            return self::view($copy, self::message($copy, $error));
        }
        $claim = $verified['claim'];
        $item = BillingImport::item($claim);
        if ($item === null) {
            MigrationClaimResume::forget();
            return self::view($copy, self::message($copy, 'invalid'));
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if ((string) ($_POST['bladecp_action'] ?? '') !== 'migration_claim_confirm' || !self::validCsrf()) {
                return self::view($copy, self::form($copy, $item, $clientId, self::text($copy, 'session')));
            }
            $result = BillingImport::claim($verified['gateway'], $claim, $clientId);
            if (($result['ok'] ?? false) === true) {
                MigrationClaimResume::markDone($token);
                MigrationClaimResume::forget();
                return self::view($copy, self::done($copy));
            }
            $error = (string) ($result['error'] ?? '');
            $key = match ($error) {
                BillingImport::REASON_CONFLICT => 'used',
                'not_linkable' => 'ended',
                BillingImport::REASON_CLIENT => 'profile',
                BillingImport::REASON_NO_PRODUCT, BillingImport::REASON_PRODUCTS => 'unavailable_provider',
                default => 'failed',
            };
            if (in_array($key, ['used', 'ended'], true)) {
                MigrationClaimResume::forget();
                return self::view($copy, self::message($copy, $key));
            }
            return self::view($copy, self::form($copy, $item, $clientId, self::text($copy, $key)));
        }
        MigrationClaimResume::markPrompted();
        return self::view($copy, self::form($copy, $item, $clientId, ''));
    }

    /** @return array<string,string> */
    public static function copy(?string $locale = null): array
    {
        $locale ??= is_string($_SESSION['Language'] ?? null) ? $_SESSION['Language']
            : (is_string($GLOBALS['CONFIG']['Language'] ?? null) ? $GLOBALS['CONFIG']['Language'] : 'english');
        $locale = match (strtolower(str_replace('_', '-', $locale))) {
            'pt', 'pt-br', 'pt-pt', 'portuguese', 'portuguese-br', 'portuguese-pt', 'portugues', 'portugues-br' => 'portuguese-br',
            'es', 'es-es', 'spanish' => 'spanish',
            default => 'english',
        };
        $copy = require dirname(__DIR__) . '/lang/claim/' . $locale . '.php';
        return is_array($copy) ? $copy : [];
    }

    /**
     * Resolves the token with the partner key of each enabled server. A token
     * of another partner is indistinguishable from an unknown one.
     *
     * @return array{ok:bool,error?:string,gateway?:FastDeployGateway,claim?:array<string,mixed>}
     */
    private static function verify(string $token): array
    {
        $error = 'invalid';
        foreach (FastDeploy\BillingSettings::serverIds() as $serverId) {
            $gateway = FastDeployGateway::forBilling((int) $serverId);
            if ($gateway === null || !method_exists($gateway, 'verifyClaim')) {
                continue;
            }
            try {
                $claim = $gateway->verifyClaim($token);
            } catch (ApiException $exception) {
                $code = strtoupper((string) (($exception->body ?? [])['code'] ?? ''));
                $error = match (true) {
                    $exception->httpStatus === 404 => $error,
                    $exception->httpStatus === 410 || $code === 'MIGRATION_CLAIM_EXPIRED' => 'expired',
                    $code === 'MIGRATION_CLAIM_ALREADY_USED' => 'used',
                    $code === 'MIGRATION_NOT_LINKABLE' => 'ended',
                    $code === 'MIGRATION_CLAIM_NOT_REQUIRED' => 'invalid',
                    default => 'unavailable',
                };
                if ($error !== 'invalid') {
                    return ['ok' => false, 'error' => $error];
                }
                continue;
            } catch (\Throwable) {
                return ['ok' => false, 'error' => 'unavailable'];
            }
            $installation = strtolower(trim((string) ($claim['installation_id'] ?? $claim['installationId'] ?? '')));
            if ($installation !== '' && !hash_equals(strtolower($gateway->installationId()), $installation)) {
                continue;
            }
            return ['ok' => true, 'gateway' => $gateway, 'claim' => $claim];
        }
        return ['ok' => false, 'error' => $error];
    }

    /** @param array<string,string> $copy */
    private static function form(array $copy, array $item, int $clientId, string $notice): string
    {
        $owner = BillingImport::owner($clientId);
        $account = $owner === null ? '' : trim($owner['first_name'] . ' ' . $owner['last_name']) . ' (' . $owner['email'] . ')';
        $domain = (string) ($item['domain'] ?? $item['username'] ?? '');
        $html = '<h2 class="bcp-claim__title">' . self::e(self::text($copy, 'title')) . '</h2>'
            . '<p>' . self::e(self::text($copy, 'intro', ['domain' => $domain])) . '</p>'
            . '<dl class="bcp-claim__facts"><dt>' . self::e(self::text($copy, 'site')) . '</dt><dd>' . self::e($domain) . '</dd>'
            . '<dt>' . self::e(self::text($copy, 'account')) . '</dt><dd>' . self::e($account) . '</dd></dl>';
        if ($notice !== '') {
            $html .= '<div class="alert alert-warning" role="alert">' . self::e($notice) . '</div>';
        }
        $html .= '<form method="post" action="index.php?m=bladecp&amp;action=claim">'
            . '<input type="hidden" name="token" value="' . self::e(self::csrfToken()) . '">'
            . '<input type="hidden" name="bladecp_action" value="migration_claim_confirm">'
            . '<button type="submit" class="btn btn-primary">' . self::e(self::text($copy, 'confirm')) . '</button>'
            . '</form><p class="bcp-claim__note">' . self::e(self::text($copy, 'note')) . '</p>';
        return $html;
    }

    /** @param array<string,string> $copy */
    private static function done(array $copy): string
    {
        return '<h2 class="bcp-claim__title">' . self::e(self::text($copy, 'done_title')) . '</h2>'
            . '<p role="status">' . self::e(self::text($copy, 'done')) . '</p>';
    }

    /** @param array<string,string> $copy */
    private static function message(array $copy, string $key, string $tone = 'danger'): string
    {
        return '<h2 class="bcp-claim__title">' . self::e(self::text($copy, 'title')) . '</h2>'
            . '<div class="alert alert-' . ($tone === 'info' ? 'info' : 'danger') . '" role="alert">' . self::e(self::text($copy, $key)) . '</div>';
    }

    /** @param array<string,string> $copy @return array<string,mixed> */
    private static function view(array $copy, string $html): array
    {
        return [
            'pagetitle' => self::text($copy, 'title'),
            'breadcrumb' => ['index.php?m=bladecp&action=claim' => self::text($copy, 'title')],
            'templatefile' => 'migration-claim',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => ['claimHtml' => '<div class="bcp-claim">' . $html . '</div>'],
        ];
    }

    /** @param array<string,string> $copy @param array<string,scalar> $replace */
    private static function text(array $copy, string $key, array $replace = []): string
    {
        $value = $copy[$key] ?? $copy['failed'] ?? '';
        foreach ($replace as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }
        return $value;
    }

    private static function csrfToken(): string
    {
        try {
            return function_exists('generate_token') ? (string) generate_token('plain') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function validCsrf(): bool
    {
        try {
            return function_exists('check_token') && check_token('WHMCS.default') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function protectResponse(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
    }

    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
