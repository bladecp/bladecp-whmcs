<style>{literal}
  .bladecp-federation {
    --bcp-federation-surface: #fff;
    --bcp-federation-border: #e2e8f0;
    --bcp-federation-ink: #0f172a;
    --bcp-federation-muted: #64748b;
    --bcp-federation-accent: #4f46e5;
    --bcp-federation-track: #e0e7ff;
    display: grid;
    min-height: 24rem;
    place-items: center;
    padding: 3rem 1rem;
  }
  .bladecp-federation__card {
    background: var(--bcp-federation-surface);
    border: 1px solid var(--bcp-federation-border);
    border-radius: 1.25rem;
    box-shadow: 0 16px 44px rgba(15, 23, 42, .10);
    max-width: 33rem;
    padding: 2.5rem;
    text-align: center;
    width: 100%;
  }
  .bladecp-federation #bladecp-federation-card .bladecp-federation__brand { color: var(--bcp-federation-ink); margin: 0 0 2rem; }
  .bladecp-federation__brand svg { display: inline-block; height: auto; max-width: 80%; width: 280px; }
  .bladecp-federation__spinner {
    animation: bladecp-federation-spin .8s linear infinite;
    border: 3px solid var(--bcp-federation-track);
    border-radius: 999px;
    border-top-color: var(--bcp-federation-accent);
    height: 3rem;
    margin: 0 auto 1.5rem;
    width: 3rem;
  }
  .bladecp-federation #bladecp-federation-card .bladecp-federation__title { color: var(--bcp-federation-ink); font-size: 1.45rem; font-weight: 700; margin: 0 0 .65rem; }
  .bladecp-federation__copy { color: var(--bcp-federation-muted); margin: 0; }
  .bladecp-federation__fallback { margin-top: 1.5rem; }
  .bladecp-federation__action {
    background: var(--bcp-federation-accent) !important;
    border-color: var(--bcp-federation-accent) !important;
    border-radius: .65rem;
    font-weight: 600;
    min-height: 2.7rem;
    padding: .6rem 1.15rem;
  }
  .bladecp-federation__action[aria-disabled="true"] { cursor: progress; opacity: .72; pointer-events: none; }
  .bladecp-federation__error { color: var(--bcp-federation-muted); margin: 0; }
  @keyframes bladecp-federation-spin { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) { .bladecp-federation__spinner { animation: none; } }
  @media (max-width: 575px) { .bladecp-federation { min-height: 19rem; padding: 1.5rem .875rem; } .bladecp-federation__card { padding: 2rem 1.35rem; } .bladecp-federation__action { display: inline-flex; justify-content: center; width: 100%; } }
  @media (prefers-color-scheme: dark) {
    .bladecp-federation { --bcp-federation-surface: #151827; --bcp-federation-border: #30374e; --bcp-federation-ink: #f8fafc; --bcp-federation-muted: #b7c0d2; --bcp-federation-accent: #818cf8; --bcp-federation-track: #30375b; }
  }
  html.dark .bladecp-federation, html[data-theme="dark"] .bladecp-federation, body.dark .bladecp-federation, body[data-theme="dark"] .bladecp-federation { --bcp-federation-surface: #151827; --bcp-federation-border: #30374e; --bcp-federation-ink: #f8fafc; --bcp-federation-muted: #b7c0d2; --bcp-federation-accent: #818cf8; --bcp-federation-track: #30375b; }
  html.light .bladecp-federation, html[data-theme="light"] .bladecp-federation, body.light .bladecp-federation, body[data-theme="light"] .bladecp-federation { --bcp-federation-surface: #fff; --bcp-federation-border: #e2e8f0; --bcp-federation-ink: #0f172a; --bcp-federation-muted: #64748b; --bcp-federation-accent: #4f46e5; --bcp-federation-track: #e0e7ff; }
{/literal}</style>

<div class="bladecp-federation">
  <section id="bladecp-federation-card" class="bladecp-federation__card" {if !$transitionError}role="status" aria-live="polite" aria-busy="true"{/if}>
    <h1 class="bladecp-federation__brand"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 563.02 65.63" fill="currentColor" role="img" aria-label="BladeCP Vibe" style="display:inline-block;width:280px;max-width:80%;height:auto"><path d="M61.97 32.99c5.61-2.36 9.23-6.72 9.23-12.92 0-7.75-6.27-12.11-17.94-12.11H27.13l-6.12 30.63H56.6l-1.12 5.19c-.61 2.85-3.45 5.19-6.31 5.19h-1.25a16.4 16.4 0 0 1-1.9.1H13.79c-7.6.5-11.87 4.35-13.79 10.56h45.21c13.29 0 23.7-5.17 23.7-16.39 0-4.8-2.51-8.34-6.94-10.26h0zM49.79 18.52c3.84 0 6.13 1.26 6.13 4.06 0 3.84-2.81 5.9-7.97 5.9H37.47l1.99-9.97h10.33 0zm20.59 41.11H84.4l9.29-46.57c1.61-7.19-3.75-8.97-12.39-8.2L70.38 59.63h0zm56.84-40.01l-.96 4.8c-2.51-3.84-6.86-5.46-12.25-5.46-11.96 0-21.7 9.82-21.7 23.1 0 11.66 8.27 18.23 16.9 18.23 4.65 0 8.49-1.55 11.59-4.43l-.81 3.76h13.29l5.52-27.68c.48-8.3-4.3-11.52-11.57-12.33h0zM114.08 49c-4.8 0-7.6-2.95-7.6-7.6 0-6.72 3.99-11.22 9.52-11.22 4.8 0 7.6 3.03 7.6 7.68 0 6.64-3.91 11.15-9.52 11.15zm65.11-44.14l-3.91 19.41c-2.51-3.69-6.86-5.31-12.18-5.31-11.96 0-21.78 9.82-21.78 23.1 0 11.66 8.27 18.23 16.9 18.23 4.65 0 8.56-1.7 11.59-4.58l-.81 3.91h13.29l9.29-46.57c1.08-6.57-3.66-8.85-12.39-8.2h0zM163.1 49c-4.8 0-7.53-2.95-7.53-7.6 0-6.72 3.91-11.22 9.52-11.22 4.8 0 7.53 3.03 7.53 7.68 0 6.64-3.91 11.15-9.52 11.15zm70.94-12.47c0-10.48-7.53-17.57-19.71-17.57-14.39 0-24.21 9.82-24.21 23.1 0 11.15 8.42 18.23 21.26 18.23 6.05 0 12.03-1.48 17.05-5.17-1.54-3.72-4.77-7.77-11.16-6.08-9.59 2.54-12.9-1.52-13.42-5.95h29.38c.44-1.99.81-4.21.81-6.57h0zm-20.38-7.6c4.5 0 7.53 2.51 7.46 6.79h-16.46c1.33-3.99 4.28-6.79 9.01-6.79z"/><path d="M272.41 65.63c11.27 0 20.56-3.16 27.68-11.72-2.54-5.07-6.22-8.1-17.35-5.01-.02 0-.04.02-.07.03-2.68 1.34-5.56 1.91-8.55 1.91-9.65 0-15.42-5.68-15.42-14.16 0-12.44 8.11-21.91 20.38-21.91 2.3 0 4.44.35 6.38 1.08.01 0 .02 0 .03.01 12.34 4.67 18.55-1.9 20.37-3.98-4.51-7.66-13.97-11.9-25.61-11.9-23.44 0-39.58 15.96-39.58 37.87 0 16.5 12.35 27.77 31.74 27.77h0zm68.34-64.37h-26.69l-12.62 63.11c9.66.01 17.75-3.25 21.1-16.5h12.62c19.02 0 31.19-10.37 31.19-26.33 0-12.8-9.2-20.28-25.6-20.28zm-4.78 32.55h-10.64l3.7-18.48h9.38c6.22 0 9.92 2.43 9.92 7.75 0 6.94-4.52 10.73-12.35 10.73h0z" fill-rule="evenodd"/><path d="M407.86 59.6l-12-51.84h12.37l10.44 45.99h-7.78l29.18-45.99h12.44L419.7 59.6h-11.85zm38.59 0l7.92-39.84h11.48l-7.92 39.84h-11.48zm15.62-45.4c-2.02 0-3.65-.58-4.89-1.74s-1.85-2.58-1.85-4.26c0-1.97.7-3.62 2.11-4.92 1.41-1.31 3.25-1.96 5.52-1.96 2.02 0 3.67.56 4.92 1.67 1.26 1.11 1.89 2.48 1.89 4.11 0 2.12-.7 3.84-2.11 5.15s-3.27 1.96-5.59 1.96h0zm6.6 45.4l10.96-54.95h11.55l-4.67 23.18-3.11 11.63-1.78 10.22-2 9.92h-10.96zm25.47.59c-3.06 0-5.74-.54-8.04-1.63a11.72 11.72 0 0 1-5.33-5c-1.26-2.25-1.89-5.07-1.89-8.48 0-3.6.44-6.99 1.33-10.15s2.2-5.91 3.93-8.26 3.84-4.18 6.33-5.52c2.49-1.33 5.32-2 8.48-2s5.97.7 8.59 2.11 4.71 3.43 6.29 6.07 2.37 5.86 2.37 9.66c0 3.31-.57 6.38-1.7 9.22-1.14 2.84-2.7 5.3-4.7 7.37a21.61 21.61 0 0 1-7 4.85c-2.67 1.16-5.55 1.74-8.66 1.74h0zm-1.33-9.55c2.32 0 4.34-.56 6.07-1.67s3.1-2.64 4.11-4.59 1.52-4.23 1.52-6.85-.79-4.86-2.37-6.44-3.83-2.37-6.74-2.37c-2.22 0-4.22.56-6 1.67s-3.16 2.64-4.15 4.59-1.48 4.23-1.48 6.85.79 4.86 2.37 6.44 3.8 2.37 6.67 2.37zm48.14 9.55c-4.15 0-7.74-.74-10.78-2.22s-5.39-3.55-7.07-6.22-2.52-5.8-2.52-9.41c0-4.44 1-8.41 3-11.89s4.76-6.23 8.29-8.26c3.53-2.02 7.59-3.04 12.18-3.04 3.9 0 7.26.73 10.07 2.18 2.81 1.46 5 3.48 6.55 6.07 1.56 2.59 2.33 5.69 2.33 9.29 0 1.04-.06 2.07-.19 3.11-.12 1.04-.28 2.02-.48 2.96h-32.96l1.18-6.74h26.37l-4.74 2.07c.39-2.22.27-4.09-.37-5.59-.64-1.51-1.68-2.67-3.11-3.48s-3.16-1.22-5.18-1.22c-2.52 0-4.65.6-6.41 1.81-1.75 1.21-3.07 2.88-3.96 5s-1.33 4.52-1.33 7.18c0 3.06.85 5.34 2.55 6.85s4.28 2.26 7.74 2.26c2.02 0 3.97-.32 5.85-.96s3.48-1.56 4.81-2.74l4.81 7.63c-2.32 1.83-4.92 3.17-7.81 4.04-2.89.86-5.84 1.3-8.85 1.3z"/></svg></h1>
    {if $supportConflict|default:false}
      <h2 class="bladecp-federation__title">{$bladecpCopy.switch_title|escape:'html'}</h2>
      <p class="bladecp-federation__copy">{$bladecpCopy.switch_text|escape:'html'}</p>
      <form method="post" action="{$supportCallbackUrl|escape:'html'}" class="bladecp-federation__fallback">
        <input type="hidden" name="token" value="{$token|escape:'html'}">
        <input type="hidden" name="confirmSwitch" value="1">
        <button type="submit" class="btn btn-primary bladecp-federation__action">{$bladecpCopy.switch_button|escape:'html'}</button>
      </form>
      <p class="bladecp-federation__copy">{$bladecpCopy.switch_keep|escape:'html'}</p>
    {elseif $transitionError}
      <h1 class="bladecp-federation__title">{$bladecpCopy.open_failed_title|escape:'html'}</h1>
      <p class="bladecp-federation__error">{if $transitionMessage}{$transitionMessage|escape:'html'}{else}{$bladecpCopy.back_and_retry|escape:'html'}{/if}</p>
      <p class="bladecp-federation__fallback"><a class="btn btn-primary bladecp-federation__action" href="clientarea.php">{$bladecpCopy.back_to_client_area|escape:'html'}</a></p>
    {elseif $transitionUrl}
      <div class="bladecp-federation__spinner" aria-hidden="true"></div>
      <h1 class="bladecp-federation__title">{$bladecpCopy.opening_title|escape:'html'}</h1>
      <p id="bladecp-federation-copy" class="bladecp-federation__copy">{$bladecpCopy.opening_text|escape:'html'}</p>
      <a id="bladecp-federation-next" href="{$transitionUrl|escape:'html'}" hidden aria-hidden="true"></a>
      <script>{literal}(function(){
        var card = document.getElementById('bladecp-federation-card');
        var link = document.getElementById('bladecp-federation-next');
        var copy = document.getElementById('bladecp-federation-copy');
        var started = false;
        function continueToVibe() {
          if (started || !link) return;
          started = true;
          try { window.location.replace(link.href); }
          catch (error) {
            card && card.setAttribute('aria-busy', 'false');
            if (copy) copy.textContent = '{/literal}{$bladecpCopy.opening_not_started|escape:'javascript'}{literal}';
          }
        }
        if (window.requestAnimationFrame) window.requestAnimationFrame(continueToVibe);
        else continueToVibe();
      })();{/literal}</script>
      <noscript><p class="bladecp-federation__fallback"><a class="btn btn-primary bladecp-federation__action" href="{$transitionUrl|escape:'html'}">{$bladecpCopy.continue_to_vibe|escape:'html'}</a></p></noscript>
    {elseif $autoSubmit && $token}
      <div class="bladecp-federation__spinner" aria-hidden="true"></div>
      <h1 class="bladecp-federation__title">{$bladecpCopy.preparing_access_title|escape:'html'}</h1>
      <p class="bladecp-federation__copy">{$bladecpCopy.confirming_session|escape:'html'}</p>
      <form id="bladecp-federation-start" method="post" action="{if $transitionFormUrl|default:''}{$transitionFormUrl|escape:'html'}{else}index.php?m=bladecp&amp;action=vibe-federation{/if}">
        <input type="hidden" name="token" value="{$token|escape:'html'}">
        <input type="hidden" name="serviceid" value="{$serviceId|escape:'html'}">
        {if $supportLaunchToken|default:''}<input type="hidden" name="launchToken" value="{$supportLaunchToken|escape:'html'}">{/if}
        <noscript><button type="submit" class="btn btn-primary bladecp-federation__action">{$bladecpCopy.continue|escape:'html'}</button></noscript>
      </form>
      <script>{literal}(function(){
        var form = document.getElementById('bladecp-federation-start');
        function submitOnce() {
          if (!form || form.getAttribute('data-submitting') === 'true') return;
          form.setAttribute('data-submitting', 'true');
          if (typeof form.requestSubmit === 'function') form.requestSubmit();
          else form.submit();
        }
        if (window.requestAnimationFrame) window.requestAnimationFrame(submitOnce);
        else submitOnce();
      })();{/literal}</script>
    {else}
      <h1 class="bladecp-federation__title">{$bladecpCopy.refresh_title|escape:'html'}</h1>
      <p class="bladecp-federation__copy">{$bladecpCopy.secure_transition_failed|escape:'html'}</p>
      <p class="bladecp-federation__fallback"><a class="btn btn-primary bladecp-federation__action" href="clientarea.php">{$bladecpCopy.back_to_client_area|escape:'html'}</a></p>
    {/if}
  </section>
</div>
<script>{literal}(function(){
  if (window.history && window.history.replaceState) {
    window.history.replaceState(null, document.title, 'index.php?m=bladecp&action=vibe-federation');
  }
})();{/literal}</script>
