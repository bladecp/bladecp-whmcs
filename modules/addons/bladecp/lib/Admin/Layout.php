<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\AdminAssets;
use WHMCS\Module\Server\Bladecp\Ui\Assets;
use WHMCS\Module\Server\Bladecp\Ui\Brand;
use WHMCS\Module\Server\Bladecp\Ui\Icons;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Elementos de apresentação compartilhados pelas abas administrativas.
 * Mantém a página principal focada em roteamento e regras da tela.
 */
final class Layout
{
    public static function shell(string $inner): string
    {
        $css = class_exists(Assets::class) ? Assets::css() : '';
        $js = '<script>(function(){try{'
            . 'var box=document.querySelector(".bcp-pick");if(!box)return;'
            . 'box.addEventListener("change",function(e){'
            . 'var cb=e.target;if(!cb||cb.type!=="checkbox"||!cb.checked)return;'
            . 'var lab=cb.closest(".bcp-chips-opt");if(!lab)return;'
            . 'var solo=lab.getAttribute("data-solo")==="1";'
            . 'var cat=lab.getAttribute("data-cat");'
            . 'var all=box.querySelectorAll(\'.bcp-chips-opt input[type=checkbox]\');'
            . '[].forEach.call(all,function(o){'
            . 'if(o===cb)return;var l=o.closest(".bcp-chips-opt");if(!l)return;'
            . 'if(solo){o.checked=false;return;}'
            . 'if(l.getAttribute("data-solo")==="1"){o.checked=false;return;}'
            . 'if(cat&&l.getAttribute("data-cat")===cat){o.checked=false;}'
            . '});});'
            . '}catch(e){}})();</script>';

        return '<style>' . $css . self::css()
            . '</style><div class="bcp-shell bcp-addon">' . $inner
            . '</div>' . $js;
    }

    public static function card(string $head, string $body): string
    {
        return '<div class="bcp-card">' . $head
            . '<div class="bcp-card-bd">' . $body . '</div></div>';
    }

    public static function header(string $title, string $sub, bool $showLogo = true): string
    {
        $logo = $showLogo && class_exists(Brand::class) ? Brand::logo(22) : '';
        $mark = $showLogo
            ? '<span class="bcp-card-mark" aria-hidden="true">' . self::icon('server') . '</span>'
            : '';

        return '<div class="bcp-card-hd">' . $mark . '<div class="bcp-card-hd-t">'
            . '<h3>' . self::escape($title) . '</h3>'
            . ($sub !== '' ? '<p>' . self::escape($sub) . '</p>' : '')
            . '</div>' . $logo . '</div>';
    }

    public static function tabs(string $base, string $active): string
    {
        $pid = isset($_GET['pid']) ? (int) $_GET['pid'] : 0;
        $q = $pid > 0 ? '&pid=' . $pid : '';
        $items = [
            'overview' => ['&action=overview', 'Visão geral', 'layers'],
            'setup' => ['&action=setup', 'Configuração', 'plug'],
            'planos' => ['&action=planos', 'Planos', 'layers'],
            'combos' => ['&action=combos', 'Combos', 'package'],
            'sync' => ['&action=orderform', 'Componentes', 'refresh'],
            'options' => ['&action=options', 'Opções', 'plug'],
            'prices' => ['&action=prices', 'Preços', 'download'],
            'marketplace' => ['&action=marketplace', 'Marketplace', 'receipt'],
            'webhooks' => ['&action=webhooks', 'Webhooks', 'webhook'],
            'operation' => ['&action=operation', 'Operação', 'package'],
            'diagnostics' => ['&action=diagnostics', 'Diagnóstico', 'refresh'],
        ];
        $out = '<div class="bcp-tabs">';
        foreach ($items as $key => [$qs, $label, $icon]) {
            $global = in_array($key, ['overview', 'setup', 'prices', 'marketplace', 'webhooks', 'operation', 'diagnostics', 'planos'], true);
            $href = $base . $qs . ($global ? '' : $q);
            $out .= '<a class="bcp-tab' . ($key === $active ? ' is-active' : '') . '" href="'
                . self::escape($href) . '">' . self::icon($icon) . self::escape($label) . '</a>';
        }
        return $out . '</div>';
    }

    public static function icon(string $name): string
    {
        return Icons::get($name);
    }

    public static function token(): string
    {
        return function_exists('generate_token') ? (string) generate_token('WHMCS.admin.default') : '';
    }

    public static function checkToken(): bool
    {
        if (!function_exists('check_token')) {
            return false;
        }
        try {
            return (bool) check_token('WHMCS.admin.default');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function docsLink(string $label, string $class = ''): string
    {
        return '<a' . ($class !== '' ? ' class="' . self::escape($class) . '"' : '')
            . ' href="https://github.com/bladecp/bladecp-whmcs/blob/main/docs/configuration.md"'
            . ' target="_blank" rel="noopener noreferrer">'
            . self::escape($label) . '<span class="sr-only"> (abre em nova aba)</span>'
            . self::icon('external-link') . '</a>';
    }

    private static function css(): string
    {
        return AdminAssets::css();
    }
}
