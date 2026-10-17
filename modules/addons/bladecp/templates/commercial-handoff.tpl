{if $redirectTo}
<div class="bladecp-commercial-brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
<script>
  (function () {
    try { window.history.replaceState({}, document.title, 'index.php?m=bladecp&action=commercial-handoff'); } catch (e) {}
    window.location.replace({$redirectTo|@json_encode nofilter});
  }());
</script>
<noscript><p><a href="{$redirectTo|escape}">{$bladecpCopy.continue|escape:'html'}</a></p></noscript>
{else}
<div class="bladecp-commercial-brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
<div class="alert alert-danger" role="alert">{$commercialActionError|default:$bladecpCopy.action_failed_default|escape}</div>
{/if}
<style>{literal}
.bladecp-commercial-brand { align-items: center; display: flex; justify-content: center; margin: 2rem 1rem 1rem; }
.bladecp-commercial-brand .bladecp-partner-brand { display: inline-flex; line-height: 0; max-width: 100%; }
.bladecp-commercial-brand .bladecp-partner-brand img { display: block; max-width: min(15rem, 100%); object-fit: contain; width: auto; }
.bladecp-commercial-brand .bladecp-partner-brand__dark { display: none !important; }
@media (prefers-color-scheme: dark) { .bladecp-commercial-brand .bladecp-partner-brand__light { display: none !important; } .bladecp-commercial-brand .bladecp-partner-brand__dark { display: block !important; } }
html.dark .bladecp-commercial-brand .bladecp-partner-brand__light, html[data-theme="dark"] .bladecp-commercial-brand .bladecp-partner-brand__light, body.dark .bladecp-commercial-brand .bladecp-partner-brand__light, body[data-theme="dark"] .bladecp-commercial-brand .bladecp-partner-brand__light { display: none !important; }
html.dark .bladecp-commercial-brand .bladecp-partner-brand__dark, html[data-theme="dark"] .bladecp-commercial-brand .bladecp-partner-brand__dark, body.dark .bladecp-commercial-brand .bladecp-partner-brand__dark, body[data-theme="dark"] .bladecp-commercial-brand .bladecp-partner-brand__dark { display: block !important; }
{/literal}</style>
