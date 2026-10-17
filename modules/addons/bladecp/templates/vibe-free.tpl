<style>{literal}
  .bladecp-vibe-free {
    --bladecp-vibe-surface: #ffffff;
    --bladecp-vibe-border: #e2e8f0;
    --bladecp-vibe-ink: #0f172a;
    --bladecp-vibe-muted: #475569;
    --bladecp-vibe-accent: #2563eb;
    --bladecp-vibe-accent-hover: #1d4ed8;
    --bladecp-vibe-spinner-track: #dbeafe;
    display: grid;
    min-height: 22rem;
    place-items: center;
    padding: 3rem 1rem;
  }
  .bladecp-vibe-free__card {
    background: var(--bladecp-vibe-surface);
    border: 1px solid var(--bladecp-vibe-border);
    border-radius: 1.25rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, .04), 0 4px 20px rgba(0, 0, 0, .06);
    max-width: 32rem;
    padding: 2.5rem;
    text-align: center;
    width: 100%;
  }
  .bladecp-vibe-free__brand { display: flex; justify-content: center; margin: 0 0 1.25rem; min-height: 2rem; }
  .bladecp-partner-brand { align-items: center; display: inline-flex; line-height: 0; max-width: 100%; }
  .bladecp-partner-brand img { display: block; max-width: min(15rem, 100%); object-fit: contain; width: auto; }
  .bladecp-partner-brand__dark { display: none !important; }
  @media (prefers-color-scheme: dark) { .bladecp-partner-brand__light { display: none !important; } .bladecp-partner-brand__dark { display: block !important; } }
  html.dark .bladecp-partner-brand__light, html[data-theme="dark"] .bladecp-partner-brand__light, body.dark .bladecp-partner-brand__light, body[data-theme="dark"] .bladecp-partner-brand__light { display: none !important; }
  html.dark .bladecp-partner-brand__dark, html[data-theme="dark"] .bladecp-partner-brand__dark, body.dark .bladecp-partner-brand__dark, body[data-theme="dark"] .bladecp-partner-brand__dark { display: block !important; }
  html.light .bladecp-partner-brand__light, html[data-theme="light"] .bladecp-partner-brand__light, body.light .bladecp-partner-brand__light, body[data-theme="light"] .bladecp-partner-brand__light { display: block !important; }
  html.light .bladecp-partner-brand__dark, html[data-theme="light"] .bladecp-partner-brand__dark, body.light .bladecp-partner-brand__dark, body[data-theme="light"] .bladecp-partner-brand__dark { display: none !important; }
  .bladecp-vibe-free__transition {
    max-width: 32rem;
    padding: 2rem 1rem;
    text-align: center;
    width: 100%;
  }
  .bladecp-vibe-free__spinner {
    animation: bladecp-vibe-free-spin 0.85s linear infinite;
    border: 3px solid var(--bladecp-vibe-spinner-track);
    border-radius: 999px;
    border-top-color: var(--bladecp-vibe-accent);
    height: 2.75rem;
    margin: 0 auto 1.5rem;
    width: 2.75rem;
  }
  .bladecp-vibe-free__title {
    color: var(--bladecp-vibe-ink) !important;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 0.75rem;
  }
  .bladecp-vibe-free__copy {
    color: var(--bladecp-vibe-muted);
    margin: 0;
  }
  .bladecp-vibe-free__error {
    color: var(--bladecp-vibe-muted);
    margin: 0 0 1.5rem;
  }
  .bladecp-vibe-free__fallback {
    display: none;
    margin-top: 1.5rem;
  }
  .bladecp-vibe-free__action--primary { display: inline-flex; margin-top: 1.25rem; }
  .bladecp-vibe-free .bladecp-vibe-free__action {
    background: var(--bladecp-vibe-accent);
    border: 1px solid var(--bladecp-vibe-accent);
    border-radius: .65rem;
    box-shadow: none;
    color: #fff;
    font-weight: 600;
    min-height: 2.75rem;
    padding: .62rem 1.2rem;
    text-decoration: none;
    transition: background-color .16s ease, border-color .16s ease, transform .16s ease;
  }
  .bladecp-vibe-free .bladecp-vibe-free__action:hover {
    background: var(--bladecp-vibe-accent-hover);
    border-color: var(--bladecp-vibe-accent-hover);
    color: #fff;
    transform: translateY(-1px);
  }
  .bladecp-vibe-free .bladecp-vibe-free__action:focus-visible {
    outline: 3px solid rgba(37, 99, 235, .32);
    outline-offset: 3px;
  }
  .bladecp-vibe-free__card.is-stalled .bladecp-vibe-free__fallback,
  .bladecp-vibe-free__card.has-error .bladecp-vibe-free__fallback {
    display: inline-flex;
  }
  @keyframes bladecp-vibe-free-spin {
    to { transform: rotate(360deg); }
  }
  @media (prefers-reduced-motion: reduce) {
    .bladecp-vibe-free__spinner { animation-duration: 1.6s; }
    .bladecp-vibe-free .bladecp-vibe-free__action { transition: none; }
  }
  @media (max-width: 575px) {
    .bladecp-vibe-free { min-height: 18rem; padding: 1.5rem .875rem; }
    .bladecp-vibe-free__card { padding: 2rem 1.35rem; }
    .bladecp-vibe-free .bladecp-vibe-free__action { width: 100%; }
  }
  @media (prefers-color-scheme: dark) {
    .bladecp-vibe-free {
      --bladecp-vibe-surface: #151d2d;
      --bladecp-vibe-border: #34425d;
      --bladecp-vibe-ink: #f4f7fb;
      --bladecp-vibe-muted: #b6c2d4;
      --bladecp-vibe-accent: #1d4ed8;
      --bladecp-vibe-accent-hover: #1e40af;
      --bladecp-vibe-spinner-track: #2b3b58;
    }
    .bladecp-vibe-free__card { box-shadow: 0 24px 60px rgba(0, 0, 0, .32); }
  }
  html.dark .bladecp-vibe-free,
  html[data-theme="dark"] .bladecp-vibe-free,
  body[data-theme="dark"] .bladecp-vibe-free,
  body.dark .bladecp-vibe-free {
    --bladecp-vibe-surface: #151d2d;
    --bladecp-vibe-border: #34425d;
    --bladecp-vibe-ink: #f4f7fb;
    --bladecp-vibe-muted: #b6c2d4;
    --bladecp-vibe-accent: #1d4ed8;
    --bladecp-vibe-accent-hover: #1e40af;
    --bladecp-vibe-spinner-track: #2b3b58;
  }
  html.dark .bladecp-vibe-free__card,
  html[data-theme="dark"] .bladecp-vibe-free__card,
  body[data-theme="dark"] .bladecp-vibe-free__card,
  body.dark .bladecp-vibe-free__card { box-shadow: 0 24px 60px rgba(0, 0, 0, .32); }
  html.light .bladecp-vibe-free,
  html[data-theme="light"] .bladecp-vibe-free,
  body[data-theme="light"] .bladecp-vibe-free,
  body.light .bladecp-vibe-free {
    --bladecp-vibe-surface: #ffffff;
    --bladecp-vibe-border: #e2e8f0;
    --bladecp-vibe-ink: #0f172a;
    --bladecp-vibe-muted: #475569;
    --bladecp-vibe-accent: #2563eb;
    --bladecp-vibe-accent-hover: #1d4ed8;
    --bladecp-vibe-spinner-track: #dbeafe;
  }
  html.light .bladecp-vibe-free__card,
  html[data-theme="light"] .bladecp-vibe-free__card,
  body[data-theme="light"] .bladecp-vibe-free__card,
  body.light .bladecp-vibe-free__card { box-shadow: 0 1px 3px rgba(0, 0, 0, .04), 0 4px 20px rgba(0, 0, 0, .06); }
  @media (prefers-reduced-motion: reduce) {
    .bladecp-vibe-free .bladecp-vibe-free__action:hover { transform: none; }
  }
{/literal}</style>

{if $stripIdentityCallbackUrl || $stripLaunchStartUrl}
  <script>{literal}(function(){
    if (window.history && typeof window.history.replaceState === 'function') {
      window.history.replaceState(null, document.title, 'index.php?m=bladecp&action=vibe-free');
    }
  })();{/literal}</script>
{/if}

{if $redirectTo}
  <div class="bladecp-vibe-free">
    <section id="bladecp-vibe-free-success" class="bladecp-vibe-free__transition" role="status" aria-live="polite">
      <div class="bladecp-vibe-free__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
      <div class="bladecp-vibe-free__spinner" aria-hidden="true"></div>
      <h1 class="bladecp-vibe-free__title">{$bladecpCopy.ready_title|escape:'html'}</h1>
      <p class="bladecp-vibe-free__copy">{$bladecpCopy.ready_text|escape:'html'}</p>
      <p><a id="bladecp-vibe-free-redirect" class="btn btn-primary bladecp-vibe-free__action bladecp-vibe-free__fallback" href="{$redirectTo|escape:'html'}">{$bladecpCopy.open_vibe|escape:'html'}</a></p>
    </section>
  </div>
  <script>{literal}
    (function () {
      var card = document.getElementById('bladecp-vibe-free-success');
      var link = document.getElementById('bladecp-vibe-free-redirect');
      window.setTimeout(function () { card && card.classList.add('is-stalled'); }, 3000);
      try {
        link && window.location.replace(link.href);
      } catch (error) {
        card && card.classList.add('is-stalled');
      }
    })();
  {/literal}</script>
  <noscript>
    <style>{literal}.bladecp-vibe-free__fallback { display: inline-flex; }{/literal}</style>
  </noscript>
{elseif $identityRedirect}
  <div class="bladecp-vibe-free">
    <section class="bladecp-vibe-free__transition" role="status" aria-live="polite">
      <div class="bladecp-vibe-free__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
      <div class="bladecp-vibe-free__spinner" aria-hidden="true"></div>
      <h1 class="bladecp-vibe-free__title">{$bladecpCopy.confirming_title|escape:'html'}</h1>
      <p class="bladecp-vibe-free__copy">{$bladecpCopy.redirect_text|escape:'html'}</p>
    </section>
  </div>
  <script>{literal}(function(){ window.location.replace('{/literal}{$identityRedirect|escape:'javascript'}{literal}'); })();{/literal}</script>
{else}
  <div class="bladecp-vibe-free">
    {if $activationError || $identityError || !$token}
      <section id="bladecp-vibe-free-card" class="bladecp-vibe-free__card has-error" role="status" aria-live="polite">
        <div class="bladecp-vibe-free__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
        {if $identityError}
        <h1 class="bladecp-vibe-free__title">{$bladecpCopy.sign_in_title|escape:'html'}</h1>
        <p class="bladecp-vibe-free__error">{$identityError|escape:'html'}</p>
        {elseif !$token}
        <h1 class="bladecp-vibe-free__title">{$bladecpCopy.refresh_title|escape:'html'}</h1>
        <p class="bladecp-vibe-free__error">{$bladecpCopy.secure_activation_failed|escape:'html'}</p>
        {else}
        <h1 class="bladecp-vibe-free__title">{$bladecpCopy.finish_failed_title|escape:'html'}</h1>
        <p class="bladecp-vibe-free__error">{$activationError|escape:'html'}</p>
        {/if}
        <p><a class="btn btn-primary bladecp-vibe-free__action" href="index.php?m=bladecp&amp;action=vibe-free">{$bladecpCopy.try_again_button|escape:'html'}</a></p>
      </section>
    {elseif $autoSubmit}
      <section id="bladecp-vibe-free-loading" class="bladecp-vibe-free__transition" role="status" aria-live="polite">
        <div class="bladecp-vibe-free__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
        <div class="bladecp-vibe-free__spinner" aria-hidden="true"></div>
        <h1 class="bladecp-vibe-free__title">{$bladecpCopy.preparing_vibe_title|escape:'html'}</h1>
        <p class="bladecp-vibe-free__copy">{$bladecpCopy.free_activating_text|escape:'html'}</p>
        <form method="post" action="index.php?m=bladecp&amp;action=vibe-free" id="bladecp-vibe-free-activation" hidden>
          <input type="hidden" name="token" value="{$token|escape:'html'}">
          <input type="hidden" name="bladecp_action" value="vibe_free_identity_start">
          <input type="hidden" name="bladecp_trace" value="{$traceId|escape:'html'}">
        </form>
        <script>{literal}(function(){
          var form = document.getElementById('bladecp-vibe-free-activation');
          if (!form) return;
          if (typeof form.requestSubmit === 'function') form.requestSubmit();
          else form.submit();
        })();{/literal}</script>
      </section>
    {else}
      <section id="bladecp-vibe-free-card" class="bladecp-vibe-free__card has-error" role="status" aria-live="polite">
        <div class="bladecp-vibe-free__brand">{$partnerBrandLogoHtml|default:'' nofilter}</div>
        <h1 class="bladecp-vibe-free__title">{$bladecpCopy.continue_failed_title|escape:'html'}</h1>
        <p class="bladecp-vibe-free__error">{$bladecpCopy.refresh_and_retry|escape:'html'}</p>
        <p><a class="btn btn-primary bladecp-vibe-free__action" href="index.php?m=bladecp&amp;action=vibe-free">{$bladecpCopy.try_again_button|escape:'html'}</a></p>
      </section>
    {/if}
  </div>
  <noscript>
    <style>{literal}.bladecp-vibe-free__fallback { display: inline-flex; }{/literal}</style>
  </noscript>
{/if}
