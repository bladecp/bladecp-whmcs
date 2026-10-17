<style>{literal}
  .bladecp-vibe-service-identity { display:grid; min-height:20rem; place-items:center; padding:3rem 1rem; }
  .bladecp-vibe-service-identity__card { max-width:32rem; padding:2.25rem; text-align:center; width:100%; }
  .bladecp-vibe-service-identity__brand { display:flex; justify-content:center; margin-bottom:1.25rem; }
  .bladecp-vibe-service-identity__spinner { animation:bladecp-vibe-service-identity-spin .85s linear infinite; border:3px solid #dbeafe; border-radius:50%; border-top-color:#2563eb; height:2.75rem; margin:0 auto 1.25rem; width:2.75rem; }
  @keyframes bladecp-vibe-service-identity-spin { to { transform:rotate(360deg); } }
{/literal}</style>

{if $stripIdentityCallbackUrl}
<script>{literal}(function(){ if(window.history&&window.history.replaceState){window.history.replaceState(null,document.title,'index.php?m=bladecp&action=vibe-service-identity');} })();{/literal}</script>
{/if}

<div class="bladecp-vibe-service-identity">
  <section class="bladecp-vibe-service-identity__card" role="status" aria-live="polite">
    <div class="bladecp-vibe-service-identity__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
    {if $redirectTo}
      <div class="bladecp-vibe-service-identity__spinner" aria-hidden="true"></div>
      <h1>{$bladecpCopy.ready_title|escape:'html'}</h1><p>{$bladecpCopy.ready_text|escape:'html'}</p>
      <p><a class="btn btn-primary" id="bladecp-vibe-service-identity-redirect" href="{$redirectTo|escape:'html'}">{$bladecpCopy.open_vibe|escape:'html'}</a></p>
      <script>{literal}(function(){var a=document.getElementById('bladecp-vibe-service-identity-redirect');if(a){window.location.replace(a.href);}})();{/literal}</script>
    {elseif $identityRedirect}
      <div class="bladecp-vibe-service-identity__spinner" aria-hidden="true"></div>
      <h1>{$bladecpCopy.confirming_title|escape:'html'}</h1><p>{$bladecpCopy.redirect_text|escape:'html'}</p>
      <script>{literal}(function(){window.location.replace('{/literal}{$identityRedirect|escape:'javascript'}{literal}');})();{/literal}</script>
    {elseif $identityError || $activationError || !$serviceId || !$token}
      <h1>{$bladecpCopy.continue_failed_title|escape:'html'}</h1>
      <p>{if $identityError}{$identityError|escape:'html'}{elseif $activationError}{$activationError|escape:'html'}{else}{$bladecpCopy.open_service_from_client_area|escape:'html'}{/if}</p>
    {else}
      <div class="bladecp-vibe-service-identity__spinner" aria-hidden="true"></div>
      <h1>{$bladecpCopy.preparing_vibe_title|escape:'html'}</h1><p>{$bladecpCopy.pro_confirming_text|escape:'html'}</p>
      <form method="post" action="index.php?m=bladecp&amp;action=vibe-service-identity" id="bladecp-vibe-service-identity-form" hidden>
        <input type="hidden" name="token" value="{$token|escape:'html'}"><input type="hidden" name="bladecp_action" value="vibe_service_identity_start"><input type="hidden" name="serviceid" value="{$serviceId|escape:'html'}">
      </form>
      <script>{literal}(function(){var f=document.getElementById('bladecp-vibe-service-identity-form');if(f){if(typeof f.requestSubmit==='function'){f.requestSubmit();}else{f.submit();}}})();{/literal}</script>
    {/if}
  </section>
</div>
