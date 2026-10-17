<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Shows immediate visual feedback while an eligible Vibe SSO request reaches
 * WHMCS. It is deliberately presentation-only: the browser keeps the exact
 * native link or form submission, including its method and parameters.
 */
final class VibeSsoLoading
{
    public static function footerOutput(): string
    {
        return <<<'HTML'
<style>
  #bladecp-vibe-sso-loading { align-items:center; background:rgba(9,14,28,.72); color:#fff; display:flex; inset:0; justify-content:center; opacity:0; padding:1.5rem; pointer-events:none; position:fixed; transition:opacity .16s ease; z-index:2147483000; }
  #bladecp-vibe-sso-loading.is-visible { opacity:1; pointer-events:auto; }
  #bladecp-vibe-sso-loading .bladecp-vibe-sso-loading__card { align-items:center; background:#151827; border:1px solid rgba(148,163,184,.28); border-radius:1rem; box-shadow:0 22px 70px rgba(0,0,0,.35); display:flex; gap:.9rem; max-width:26rem; padding:1.1rem 1.25rem; width:100%; }
  #bladecp-vibe-sso-loading .bladecp-vibe-sso-loading__spinner { animation:bladecp-vibe-sso-spin .8s linear infinite; border:3px solid rgba(196,181,253,.35); border-radius:50%; border-top-color:#a78bfa; flex:0 0 auto; height:2rem; width:2rem; }
  #bladecp-vibe-sso-loading strong { display:block; font-size:1rem; line-height:1.25; }
  #bladecp-vibe-sso-loading span { color:#cbd5e1; display:block; font-size:.875rem; margin-top:.18rem; }
  @keyframes bladecp-vibe-sso-spin { to { transform:rotate(360deg); } }
  @media (prefers-reduced-motion:reduce) { #bladecp-vibe-sso-loading { transition:none; } #bladecp-vibe-sso-loading .bladecp-vibe-sso-loading__spinner { animation:none; } }
</style>
<script>(function(){
  'use strict';
  if (window.__bladecpVibeSsoLoading) return;
  window.__bladecpVibeSsoLoading = true;

  var overlay;
  function show() {
    if (!document.body) return;
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'bladecp-vibe-sso-loading';
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'polite');
      overlay.setAttribute('aria-label', 'Abrindo seu Vibe');
      overlay.innerHTML = '<div class="bladecp-vibe-sso-loading__card"><i class="bladecp-vibe-sso-loading__spinner" aria-hidden="true"></i><div><strong>Abrindo seu Vibe</strong><span>Estamos preparando seu acesso.</span></div></div>';
      document.body.appendChild(overlay);
    }
    document.body.setAttribute('aria-busy', 'true');
    overlay.className = 'is-visible';
  }
  function clear() {
    document.body && document.body.removeAttribute('aria-busy');
    if (overlay) overlay.className = '';
  }
  function text(value) {
    return String(value || '').toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/\s+/g, ' ').trim();
  }
  function isSameOrigin(url) {
    try { return new URL(url, window.location.href).origin === window.location.origin; }
    catch (error) { return false; }
  }
  function isDirectRoute(url) {
    try {
      var parsed = new URL(url, window.location.href);
      return parsed.origin === window.location.origin
        && parsed.searchParams.get('m') === 'bladecp'
        && parsed.searchParams.get('action') === 'vibe-federation';
    } catch (error) { return false; }
  }
  function isEligibleLink(link) {
    if (!link || link.tagName !== 'A' || link.hasAttribute('download')) return false;
    var target = (link.getAttribute('target') || '').toLowerCase();
    if (target && target !== '_self') return false;
    var href = link.getAttribute('href') || '';
    if (!isSameOrigin(href)) return false;
    if (isDirectRoute(href) || link.getAttribute('data-bladecp-vibe-sso') === 'true') return true;
    if (!/[?&]dosinglesignon=1(?:[&#]|$)/i.test(href)) return false;
    return /^(abrir|acessar) (?:o )?(?:bladecp )?vibe$/.test(text(link.textContent || link.getAttribute('aria-label')));
  }
  function isEligibleForm(form) {
    if (!form || form.tagName !== 'FORM') return false;
    return isDirectRoute(form.getAttribute('action') || window.location.href);
  }
  document.addEventListener('click', function(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var node = event.target;
    var link = node && node.closest ? node.closest('a') : null;
    if (isEligibleLink(link)) show();
  });
  document.addEventListener('submit', function(event) {
    if (!event.defaultPrevented && isEligibleForm(event.target)) show();
  });
  window.addEventListener('pageshow', clear);
})();</script>
HTML;
    }
}
