<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Optional client migration surface using the authenticated server context. */
final class FastDeployClientArea
{
    private static bool $registered = false;

    public static function previewEnabled(): bool
    {
        return defined('BLADECP_FAST_DEPLOY_PREVIEW') && BLADECP_FAST_DEPLOY_PREVIEW === true;
    }

    public static function available(): bool
    {
        try {
            if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)
                || IdentityHandoff::currentIdentity() === null) {
                return false;
            }
            $context = AdminServerContext::resolve();
            return $context['state'] === 'configured' && $context['serverId'] !== null
                && ClientExperienceSettings::fastDeployEnabled($context['serverId']);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function registerHook(): void
    {
        if (self::$registered || !function_exists('add_hook')) {
            return;
        }
        self::$registered = true;
        add_hook('ClientAreaHomepage', 2, static function (): string {
            try {
                return self::available() ? self::html('fast-deploy-card') : '';
            } catch (\Throwable) {
                return '';
            }
        });
    }

    public static function card(): string
    {
        return self::available() ? self::html('fast-deploy-card') : '';
    }

    public static function render(): array
    {
        $copy = self::copy();
        $available = self::available();
        $flow = $available ? FastDeployController::handle() : null;
        if (($_GET['format'] ?? '') === 'json' || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, max-age=0');
                header('X-Content-Type-Options: nosniff');
                http_response_code(($flow['ok'] ?? false) ? 200 : ($available ? 400 : 403));
            }
            echo json_encode($flow ?? ['ok' => false, 'error' => $copy['unavailable']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($flow['ok'] ?? false)
            && is_string($flow['detail']['requestUuid'] ?? null)) {
            $base = VibePromptCard::basePath((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''));
            if (!headers_sent()) {
                header('Location: ' . $base . '/index.php?m=bladecp&action=fast-deploy&request=' . rawurlencode($flow['detail']['requestUuid']), true, 303);
                exit;
            }
        }
        $html = '<p role="status">' . htmlspecialchars($copy['unavailable'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        if ($available) {
            try {
                $detail = $flow['detail'] ?? null;
                $base = VibePromptCard::basePath((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''));
                $html = is_array($detail)
                    ? FastDeploy\View::render($detail, $flow['history'] ?? [], FastDeployController::csrfToken(), $base)
                    : self::html('fast-deploy-page', $flow ?? []);
            } catch (\Throwable) {
                $available = false;
            }
        }
        return [
            'pagetitle' => 'Fast Deploy',
            'breadcrumb' => ['index.php?m=bladecp&action=fast-deploy' => 'Fast Deploy'],
            'templatefile' => 'fast-deploy',
            'requirelogin' => true,
            'forcessl' => !self::previewEnabled(),
            'vars' => ['fastDeployHtml' => $html, 'fastDeployAvailable' => $available],
        ];
    }

    public static function copy(?string $locale = null): array
    {
        $locale ??= is_string($_SESSION['Language'] ?? null) ? $_SESSION['Language']
            : (is_string($GLOBALS['CONFIG']['Language'] ?? null) ? $GLOBALS['CONFIG']['Language'] : 'english');
        $locale = match (strtolower(str_replace('_', '-', $locale))) {
            'pt', 'pt-br', 'portuguese', 'portuguese-br' => 'portuguese-br',
            'es', 'es-es', 'spanish' => 'spanish',
            default => 'english',
        };
        return require dirname(__DIR__) . '/lang/fast-deploy/' . $locale . '.php';
    }

    private static function html(string $template, array $flow = []): string
    {
        $copy = self::copy();
        $serverId = AdminServerContext::resolve()['serverId'];
        $catalog = $serverId !== null && $template === 'fast-deploy-page'
            ? FastDeployDestinations::load($serverId) : ['state' => 'empty', 'products' => []];
        $products = FastDeployPresentation::products($catalog['products'], $copy);
        $catalog['products'] = $products;
        $selection = FastDeployDestinations::selection($catalog, $_GET);
        $invalidSelection = !isset($_GET['refresh_plan']) && isset($_GET['product_id'], $_GET['location_id']) && $selection === null;
        $activeProduct = $selection['product'] ?? null;
        $requestedProduct = is_string($_GET['product_id'] ?? null) ? ($_GET['product_id']) : '';
        if ($activeProduct === null && ctype_digit($requestedProduct) && !empty($products[(int) $requestedProduct]['locations'])) {
            $activeProduct = $products[(int) $requestedProduct];
        }
        if ($activeProduct === null) {
            foreach ($products as $product) { if ($product['locations'] !== []) { $activeProduct = $product; break; } }
        }
        $systemUrl = $GLOBALS['CONFIG']['SystemURL'] ?? '';
        $basePath = VibePromptCard::basePath(is_string($systemUrl) ? $systemUrl : '');
        $csrf = $template === 'fast-deploy-page' ? FastDeployController::csrfToken() : '';
        $nonce = $template === 'fast-deploy-page' ? FastDeployController::nonce() : null;
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $icon = static fn (string $name): string => VibePromptCard::icon($name);
        ob_start();
        try {
            require dirname(__DIR__) . '/templates/' . $template . '.php';
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }
}
