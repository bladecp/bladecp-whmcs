{* BladeCP client area (Stack). O HTML branded é
   montado em PHP (Ui\ClientPanel; design system escopado .bcp-*,
   valores escapados lá dentro, fail-soft). O template só imprime —
   `nofilter` porque o conteúdo é HTML confiável do próprio módulo.
   Fallback mínimo se `bladecpHtml` vier vazio (degrada, não quebra). *}
{if $bladecpHtml}{$bladecpHtml nofilter}{else}
<div class="bladecp-overview">
  <table class="table">
    <tr><td><strong>{$LANG.bladecp_status|default:'Status'}</strong></td><td>{$status|escape}</td></tr>
    <tr><td><strong>{$LANG.bladecp_domain|default:'Domain'}</strong></td><td>{$primaryDomain|escape}</td></tr>
  </table>
</div>
{/if}
{if $vibeSsoUrl && !$vibePanelHasAction}
<div class="bladecp-overview">
  <p><a class="btn btn-primary" data-bladecp-vibe-sso="true" href="{$vibeSsoUrl|escape:'html'}">Abrir o Vibe</a></p>
</div>
{/if}
