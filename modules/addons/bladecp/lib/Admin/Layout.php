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
        return '<style>' . $css . self::css()
            . '</style><div class="bcp-shell bcp-addon" aria-busy="false">'
            . $inner . self::loadingMarkup() . '</div>' . self::script();
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
            'overview' => ['&action=overview', 'layout.tabs.overview', 'layers'],
            'setup' => ['&action=setup', 'layout.tabs.setup', 'plug'],
            'client-experience' => ['&action=client-experience', 'layout.tabs.client_experience', 'layers'],
            'migration-review' => ['&action=migration-review', 'layout.tabs.migration_review', 'receipt'],
            'planos' => ['&action=planos', 'layout.tabs.plans', 'layers'],
            'combos' => ['&action=combos', 'layout.tabs.combos', 'package'],
            'sync' => ['&action=orderform', 'layout.tabs.components', 'refresh'],
            'options' => ['&action=options', 'layout.tabs.options', 'plug'],
            'prices' => ['&action=prices', 'layout.tabs.prices', 'download'],
            'marketplace' => ['&action=marketplace', 'layout.tabs.marketplace', 'receipt'],
            'webhooks' => ['&action=webhooks', 'layout.tabs.webhooks', 'webhook'],
            'operation' => ['&action=operation', 'layout.tabs.operation', 'package'],
            'diagnostics' => ['&action=diagnostics', 'layout.tabs.diagnostics', 'refresh'],
            'about' => ['&action=about', 'layout.tabs.about', 'server'],
        ];
        $out = '<div class="bcp-tabs">';
        foreach ($items as $key => [$qs, $labelKey, $icon]) {
            $global = in_array($key, ['overview', 'setup', 'client-experience', 'migration-review', 'prices', 'marketplace', 'webhooks', 'operation', 'diagnostics', 'about', 'planos'], true);
            $href = $base . $qs . ($global ? '' : $q);
            $out .= '<a class="bcp-tab' . ($key === $active ? ' is-active' : '') . '" href="'
                . self::escape($href) . '">' . self::icon($icon)
                . self::escape(I18n::t($labelKey)) . '</a>';
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
            . self::escape($label) . '<span class="sr-only"> '
            . self::escape(I18n::t('layout.opensInNewTab')) . '</span>'
            . self::icon('external-link') . '</a>';
    }

    private static function css(): string
    {
        return AdminAssets::css();
    }

    private static function loadingMarkup(): string
    {
        $title = self::escape(I18n::t('layout.loading.title'));

        return '<div class="bcp-loading-layer" aria-hidden="true">'
            . '<div class="bcp-loading-panel" role="status" aria-live="polite">'
            . self::pixelLoader('bcp-pixel-loader-panel')
            . '<span class="bcp-loading-copy"><span class="bcp-loading-headline"><strong class="bcp-loading-label" data-bcp-label="'
            . $title . '">' . $title
            . '</strong><span class="bcp-loading-elapsed" aria-hidden="true">0.0s</span></span><small>'
            . self::escape(I18n::t('layout.loading.detail'))
            . '</small></span></div></div>';
    }

    private static function pixelLoader(string $className): string
    {
        return '<span class="bcp-pixel-loader ' . self::escape($className) . '" aria-hidden="true">'
            . str_repeat('<span class="bcp-pixel-cell"></span>', 9)
            . '</span>';
    }

    private static function script(): string
    {
        $loadingLabel = json_encode(
            I18n::t('layout.loading.action'),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($loadingLabel)) {
            $loadingLabel = '"Loading..."';
        }

        return <<<HTML
<script>
(function(){
try{
var shell=document.querySelector('.bcp-shell.bcp-addon');if(!shell)return;
var box=shell.querySelector('.bcp-pick');
if(box){box.addEventListener('change',function(e){
var cb=e.target;if(!cb||cb.type!=='checkbox'||!cb.checked)return;
var lab=cb.closest('.bcp-chips-opt');if(!lab)return;
var solo=lab.getAttribute('data-solo')==='1';var cat=lab.getAttribute('data-cat');
var all=box.querySelectorAll('.bcp-chips-opt input[type=checkbox]');
[].forEach.call(all,function(o){
if(o===cb)return;var l=o.closest('.bcp-chips-opt');if(!l)return;
if(solo){o.checked=false;return;}if(l.getAttribute('data-solo')==='1'){o.checked=false;return;}
if(cat&&l.getAttribute('data-cat')===cat){o.checked=false;}
});});}

var layer=shell.querySelector('.bcp-loading-layer');
var loadingLabel={$loadingLabel};var overlayTimer=0;var fallbackTimer=0;var elapsedRevealTimer=0;var elapsedTicker=0;var elapsedStartedAt=0;var active=null;
var elapsed=shell.querySelector('.bcp-loading-elapsed');
function pixelLoader(className){
var grid=document.createElement('span');grid.className='bcp-pixel-loader '+className;grid.setAttribute('aria-hidden','true');
for(var i=0;i<9;i++){var cell=document.createElement('span');cell.className='bcp-pixel-cell';grid.appendChild(cell);}return grid;
}
function formatElapsed(milliseconds){
var total=Math.max(0,milliseconds)/1000;if(total<60)return total.toFixed(1)+'s';
return Math.floor(total/60)+'m '+(total%60).toFixed(1)+'s';
}
function updateElapsed(){if(elapsed)elapsed.textContent=formatElapsed(Date.now()-elapsedStartedAt);}
function startElapsed(){
if(!elapsed)return;elapsedStartedAt=Date.now();elapsed.textContent='0.0s';elapsed.classList.remove('is-visible');
elapsedRevealTimer=window.setTimeout(function(){updateElapsed();elapsed.classList.add('is-visible');elapsedTicker=window.setInterval(updateElapsed,100);},1000);
}
function stopElapsed(){
window.clearTimeout(elapsedRevealTimer);window.clearInterval(elapsedTicker);elapsedRevealTimer=0;elapsedTicker=0;elapsedStartedAt=0;
if(elapsed){elapsed.classList.remove('is-visible');elapsed.textContent='0.0s';}
}
function restoreControl(){
if(!active)return;
if(active.hasAttribute('data-bcp-original-value')){
active.value=active.getAttribute('data-bcp-original-value')||'';
active.removeAttribute('data-bcp-original-value');
}else if(active.hasAttribute('data-bcp-original-html')){
active.innerHTML=active.getAttribute('data-bcp-original-html')||'';
active.removeAttribute('data-bcp-original-html');
}
active.classList.remove('bcp-is-pending');active.removeAttribute('aria-disabled');active=null;
}
function reset(){
window.clearTimeout(overlayTimer);window.clearTimeout(fallbackTimer);
stopElapsed();
shell.classList.remove('is-loading','is-loading-visible');shell.setAttribute('aria-busy','false');
if(layer)layer.setAttribute('aria-hidden','true');restoreControl();
}
function mark(control){
if(!control||!control.matches('a,button,input[type="submit"],input[type="button"]'))return;
active=control;active.classList.add('bcp-is-pending');active.setAttribute('aria-disabled','true');
if(active.matches('input[type="submit"],input[type="button"]')){
active.setAttribute('data-bcp-original-value',active.value||'');active.value=loadingLabel;return;
}
active.setAttribute('data-bcp-original-html',active.innerHTML);
var label=document.createElement('span');label.textContent=loadingLabel;
active.textContent='';active.appendChild(pixelLoader('bcp-pixel-loader-inline'));active.appendChild(label);
}
function begin(control){
if(shell.classList.contains('is-loading'))return;
shell.classList.add('is-loading');shell.setAttribute('aria-busy','true');mark(control);startElapsed();
overlayTimer=window.setTimeout(function(){
shell.classList.add('is-loading-visible');if(layer)layer.setAttribute('aria-hidden','false');
},180);
fallbackTimer=window.setTimeout(reset,60000);
}
shell.addEventListener('click',function(e){
if(e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;
var target=e.target;if(!target||!target.closest)return;var link=target.closest('a');if(!link)return;
var linkTarget=(link.getAttribute('target')||'').toLowerCase();
if(link.hasAttribute('download')||(linkTarget&&linkTarget!=='_self')||link.getAttribute('aria-disabled')==='true'||link.closest('[data-bcp-no-loading]'))return;
var href=link.getAttribute('href');if(!href||href.charAt(0)==='#')return;
var url;try{url=new URL(link.href,window.location.href);}catch(ignore){return;}
if((url.protocol!=='http:'&&url.protocol!=='https:')||url.origin!==window.location.origin)return;
if(url.pathname===window.location.pathname&&url.search===window.location.search&&url.hash)return;
begin(link);
});
shell.addEventListener('submit',function(e){
if(e.defaultPrevented)return;var submitter=e.submitter||document.activeElement;begin(submitter);
});
window.addEventListener('pageshow',reset);
}catch(ignore){}
})();
</script>
HTML;
    }
}
