{if $commercialUpgradeError}
<div class="alert alert-danger" role="alert">{$commercialUpgradeError|escape}</div>
{else}
<section class="panel panel-default" aria-labelledby="bladecp-commercial-upgrade-title">
  <div class="panel-body">
    <h1 id="bladecp-commercial-upgrade-title" class="h3">{$bladecpCopy.offer_preparing_title|escape:'html'}</h1>
    <p>{$bladecpCopy.offer_preparing_text|escape:'html'}</p>
    <form id="bladecp-commercial-upgrade" method="post" action="upgrade.php?type=package&amp;id={$serviceId|escape}">
      <input type="hidden" name="step" value="2">
      <input type="hidden" name="type" value="package">
      <input type="hidden" name="id" value="{$serviceId|escape}">
      <input type="hidden" name="token" value="{$token|escape:'html'}">
      <input type="hidden" name="pid" value="{$targetProductId|escape}">
      <input type="hidden" name="billingcycle" value="{$billingCycle|escape}">
      <input type="hidden" name="bladecp_commercial_nonce" value="{$bridgeNonce|escape}">
      <button class="btn btn-primary" type="submit">{$bladecpCopy.offer_continue|escape:'html'}</button>
    </form>
  </div>
</section>
<script>{literal}(function(){try{document.getElementById('bladecp-commercial-upgrade').submit();}catch(e){}}());{/literal}</script>
{/if}
