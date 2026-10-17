<div class="bladecp-commercial-handoff" role="status" aria-live="polite">
  <div class="bladecp-commercial-brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
  {if $contextRecoveryError}
    <div class="alert alert-danger" role="alert">{$contextRecoveryError|escape}</div>
  {else}
    <p>{$bladecpCopy.context_confirming|escape:'html'}</p>
  {/if}
</div>
{if $redirectTo}
<script>
  (function () {
    try { window.history.replaceState({}, document.title, 'index.php?m=bladecp&action=vibe-context-reauthorize'); } catch (e) {}
    window.location.replace({$redirectTo|@json_encode nofilter});
  }());
</script>
{/if}
