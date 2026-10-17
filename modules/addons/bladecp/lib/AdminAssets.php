<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Estilos exclusivos da superfície administrativa do addon.
 * Mantido separado para que AdminPage concentre rotas e marcação acessível.
 */
final class AdminAssets
{
    public static function css(): string
    {
        return <<<'CSS'
.bcp-addon{position:relative;padding:4px 0 24px}
/* largura inteira da área de conteúdo do WHMCS (sem cap) */
.bcp-addon .bcp-card{max-width:none;width:100%}
.bcp-loading-layer{
  position:absolute;inset:0;z-index:60;display:block;
  background:rgba(255,255,255,.74);backdrop-filter:blur(2px);
  opacity:0;visibility:hidden;pointer-events:none;
  transition:opacity .16s ease,visibility .16s ease;
}
.bcp-addon.is-loading{cursor:progress}
.bcp-addon.is-loading-visible .bcp-loading-layer{
  opacity:1;visibility:visible;pointer-events:auto;
}
.bcp-loading-panel{
  position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);
  display:flex;align-items:center;gap:14px;min-width:310px;
  max-width:calc(100vw - 40px);padding:15px 18px;
  border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);
  background:var(--bcp-surface);color:var(--bcp-ink);box-shadow:var(--bcp-sh);
}
.bcp-loading-copy{display:flex;flex-direction:column;gap:2px;min-width:0}
.bcp-loading-headline{display:flex;align-items:baseline;gap:10px;min-width:0}
.bcp-loading-copy strong{font-size:13.5px;line-height:1.35}
.bcp-loading-copy small{font-size:11.5px;line-height:1.4;color:var(--bcp-muted)}
.bcp-loading-label{
  position:relative;color:var(--bcp-muted);white-space:nowrap;
}
.bcp-loading-label::after{
  content:attr(data-bcp-label);position:absolute;inset:0;color:var(--bcp-ink);
  pointer-events:none;-webkit-mask-image:linear-gradient(90deg,transparent 25%,#000 50%,transparent 75%);
  mask-image:linear-gradient(90deg,transparent 25%,#000 50%,transparent 75%);
  -webkit-mask-size:200% 100%;mask-size:200% 100%;
  animation:bcp-shimmer-text 1.4s linear infinite;
}
.bcp-loading-elapsed{
  display:none;flex:0 0 auto;color:var(--bcp-muted);font-family:var(--bcp-mono);
  font-size:11.5px;line-height:1;font-variant-numeric:tabular-nums;
}
.bcp-loading-elapsed.is-visible{display:inline}
.bcp-pixel-loader{
  --bcp-pixel-size:4px;--bcp-pixel-gap:1.5px;
  display:inline-grid;grid-template-columns:repeat(3,var(--bcp-pixel-size));
  gap:var(--bcp-pixel-gap);flex:0 0 auto;color:inherit;line-height:1;
}
.bcp-pixel-cell{
  display:block;width:var(--bcp-pixel-size);height:var(--bcp-pixel-size);
  border-radius:1px;background:currentColor;opacity:.15;
  animation:bcp-pixel-on 650ms ease-in-out infinite;
}
.bcp-pixel-cell:nth-child(4){animation-delay:0ms}
.bcp-pixel-cell:nth-child(1),.bcp-pixel-cell:nth-child(5),.bcp-pixel-cell:nth-child(7){animation-delay:90ms}
.bcp-pixel-cell:nth-child(2),.bcp-pixel-cell:nth-child(6),.bcp-pixel-cell:nth-child(8){animation-delay:180ms}
.bcp-pixel-cell:nth-child(3),.bcp-pixel-cell:nth-child(9){animation-delay:270ms}
.bcp-pixel-loader-panel{--bcp-pixel-size:5px;--bcp-pixel-gap:2px}
.bcp-pixel-loader-inline{--bcp-pixel-size:3px;--bcp-pixel-gap:1.25px}
.bcp-is-pending{pointer-events:none;cursor:progress!important}
.bcp-addon :is(a,button).bcp-is-pending{display:inline-flex;align-items:center;justify-content:center;gap:8px}
.bcp-addon .bcp-is-pending .bcp-pixel-loader-inline{align-self:center;margin:0;vertical-align:middle}
.bcp-addon .bcp-is-pending>span:last-child{line-height:inherit}
@keyframes bcp-pixel-on{0%,100%{opacity:.12}50%{opacity:1}}
@keyframes bcp-shimmer-text{0%{-webkit-mask-position:200% 0;mask-position:200% 0}100%{-webkit-mask-position:-200% 0;mask-position:-200% 0}}
@media(prefers-reduced-motion:reduce){
  .bcp-loading-layer{transition:none}
  .bcp-pixel-cell{animation:none}
  .bcp-pixel-cell{opacity:.35}
  .bcp-loading-label{color:var(--bcp-ink)}
  .bcp-loading-label::after{display:none;animation:none}
}
.bcp-card-mark{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:34px;height:34px;border:1px solid rgba(79,70,229,.22);border-radius:9px;background:rgba(79,70,229,.08);color:var(--bcp-accent-deep)}
.bcp-card-mark .bcp-ico{display:block;width:18px;height:18px;margin:0;vertical-align:middle}
/* Visão geral, operação e diagnóstico. Mantém os tokens compartilhados e
   concentra os detalhes visuais nesta superfície administrativa. */
.bcp-overview-hero{
  display:grid;grid-template-columns:minmax(0,1fr) auto;gap:24px;align-items:center;
  padding:22px 24px;border:1px solid rgba(79,70,229,.24);
  border-left:3px solid var(--bcp-accent);
  border-radius:var(--bcp-r-sm);background:linear-gradient(118deg,rgba(79,70,229,.09),var(--bcp-raised) 58%);
}
.bcp-overview-top{display:flex;align-items:stretch;flex-wrap:wrap;margin:0 -6px}
.bcp-overview-top::before,.bcp-overview-top::after{display:none}
.bcp-overview-top>[class*="col-"]{display:flex;padding-right:6px;padding-left:6px}
.bcp-overview-top .bcp-overview-hero,.bcp-overview-top .bcp-server-context-card{width:100%;margin:0}
.bcp-eyebrow{display:block;color:var(--bcp-accent-deep);font-size:10.5px;font-weight:bold;letter-spacing:.1em}
.bcp-overview-hero h4,.bcp-section-heading h4,.bcp-onboarding h4,.bcp-operation-intro h4,.bcp-diagnostic-hero h4,.bcp-glossary h4{margin:5px 0 6px;font-size:20px;color:var(--bcp-ink)}
.bcp-overview-hero p,.bcp-section-heading p,.bcp-onboarding p,.bcp-operation-intro p,.bcp-diagnostic-hero p{margin:0;max-width:780px;color:var(--bcp-muted);font-size:13px;line-height:1.55}
.bcp-overview-score{display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:118px;min-height:96px;border-radius:var(--bcp-r-sm);background:var(--bcp-raised);border:1px solid var(--bcp-line)}
.bcp-overview-score strong{font-size:30px;line-height:1;color:var(--bcp-accent-deep)}
.bcp-overview-score strong small{font-size:15px;color:var(--bcp-faint)}
.bcp-overview-score span{margin-top:7px;font-size:11px;color:var(--bcp-faint)}
.bcp-overview-actions{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}
.bcp-overview-actions .bcp-ico,.bcp-docs-help .bcp-ico{margin-left:5px;vertical-align:-2px}
.bcp-overview-status{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 22px}
.bcp-status-card,.bcp-operation-card{position:relative;min-height:144px;padding:16px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}
.bcp-status-card p,.bcp-operation-card p{margin:0;color:var(--bcp-faint);font-size:11px;font-weight:bold;letter-spacing:.06em;text-transform:uppercase}
.bcp-status-card h5{margin:7px 0 5px;color:var(--bcp-ink);font-size:24px;font-weight:bold;line-height:1.15}.bcp-operation-card h5{margin:7px 0 5px;color:var(--bcp-ink);font-size:18px}
.bcp-status-card small,.bcp-operation-card small{display:block;color:var(--bcp-muted);font-size:12px;line-height:1.45}
.bcp-status-card a{display:inline-block;margin-top:11px;font-size:12px;font-weight:bold;color:var(--bcp-accent-deep)}
.bcp-status-dot{position:absolute;right:16px;top:17px;width:7px;height:7px;border-radius:50%;background:var(--bcp-faint);box-shadow:0 0 0 3px rgba(148,163,184,.16)}
.bcp-status-card.is-ok .bcp-status-dot{background:#15803d;box-shadow:0 0 0 3px rgba(22,163,74,.13)}.bcp-status-card.is-warn .bcp-status-dot{background:#b45309;box-shadow:0 0 0 3px rgba(217,119,6,.13)}.bcp-status-card.is-danger .bcp-status-dot{background:#b91c1c;box-shadow:0 0 0 3px rgba(220,38,38,.13)}
.bcp-onboarding{padding:20px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:transparent}
.bcp-section-heading{display:flex;gap:16px;justify-content:space-between;align-items:flex-start;margin-bottom:12px}
.bcp-onboarding-list{list-style:none;margin:0;padding:0}
.bcp-onboarding-step{display:grid;grid-template-columns:30px minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 0;border-top:1px solid var(--bcp-line)}
.bcp-onboarding-step:first-child{border-top:0}.bcp-step-number{display:inline-flex;align-items:center;justify-content:center;width:25px;height:25px;border-radius:50%;border:1px solid var(--bcp-line);color:var(--bcp-accent-deep);font-size:12px;font-weight:bold}.bcp-onboarding-step.is-done .bcp-step-number{border-color:rgba(22,163,74,.35);background:rgba(22,163,74,.1);color:#15803d}
.bcp-step-number .bcp-ico{display:block;width:13px;height:13px;margin:0;vertical-align:middle}
.bcp-onboarding .bcp-section-heading p{font-size:13.5px;line-height:1.55}
.bcp-onboarding-step h5{margin:0;font-size:14px;font-weight:bold;line-height:1.35;color:var(--bcp-ink)}.bcp-onboarding-step p{margin:5px 0 0;font-size:13px;line-height:1.5}.bcp-step-done{margin-left:7px;color:#15803d;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em}
.bcp-overview-context{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}.bcp-overview-context section{padding:14px 16px;border:1px solid var(--bcp-line);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-overview-context h5{margin:0;color:var(--bcp-ink);font-size:14px;font-weight:bold;line-height:1.35}.bcp-overview-context p{margin:6px 0;color:var(--bcp-muted);font-size:13px;line-height:1.5}.bcp-overview-context a{font-size:12.5px;font-weight:bold;color:var(--bcp-accent-deep)}
.bcp-operation-intro,.bcp-diagnostic-hero{padding:18px 20px;margin-bottom:16px;border:1px solid var(--bcp-line);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}
.bcp-operation-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 16px}.bcp-operation-card{min-height:108px}
.bcp-operation-summary{grid-template-columns:repeat(5,minmax(0,1fr))}.bcp-operation-summary .bcp-operation-card{min-height:94px}
.bcp-operation-filters{display:grid;grid-template-columns:minmax(190px,1.5fr) minmax(145px,.8fr) minmax(125px,.65fr) auto auto;gap:10px;align-items:end;margin:0 0 16px;padding:14px 16px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:transparent}
.bcp-operation-filters label{display:flex;flex-direction:column;gap:5px;color:var(--bcp-muted);font-size:11px;font-weight:bold}.bcp-operation-filters .bcp-fg-c{width:100%}
.bcp-operation-section{margin:16px 0;padding:17px 18px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-operation-section h4{margin:0 0 10px;font-size:14px;color:var(--bcp-ink)}.bcp-operation-section .bcp-diagnostic-list small{display:block;margin-top:4px;color:var(--bcp-faint);font-size:11px}.bcp-operation-table{margin-top:0}
.bcp-operation-table .bcp-operation-action{text-align:right;white-space:nowrap}
.bcp-operation-evidence-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:16px 0}.bcp-operation-evidence-grid .bcp-operation-section{min-width:0;margin:0;border-left:3px solid var(--bcp-accent);background:transparent}
.bcp-operation-timeline{border-left:3px solid var(--bcp-accent)}
.bcp-empty-state{padding:30px 22px;text-align:center;border:1px dashed var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-empty-state strong{color:var(--bcp-ink)}.bcp-empty-state p{max-width:580px;margin:7px auto 14px;color:var(--bcp-muted);font-size:13px;line-height:1.5}
.bcp-help-strip,.bcp-chips-note{font-size:12px;line-height:1.55}.bcp-help-strip{margin-top:16px;padding:12px 14px;border-radius:var(--bcp-r-sm);border:1px solid var(--bcp-line);color:var(--bcp-muted);background:var(--bcp-raised)}
.bcp-diagnostic-section{margin:16px 0;padding:17px 18px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-diagnostic-section h4{margin:0;font-size:14px;color:var(--bcp-ink)}
.bcp-diagnostic-section.bcp-diagnostic-overview{background:transparent}
.bcp-diagnostic-list{list-style:none;margin:0;padding:0}.bcp-diagnostic-list li{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--bcp-line)}.bcp-diagnostic-list li>.btn{margin-left:auto}.bcp-diag-state{flex:0 0 auto;min-width:84px;padding:3px 7px;text-align:center;border:1px solid rgba(22,163,74,.28);border-radius:999px;background:rgba(22,163,74,.08);color:#15803d;font-size:11px;font-weight:bold}.bcp-diag-state.is-muted,.bcp-diag-state.is-unknown{border-color:var(--bcp-line);background:transparent;color:var(--bcp-faint)}.bcp-diag-state.is-attention,.bcp-diag-state.is-warn{border-color:rgba(217,119,6,.30);background:rgba(217,119,6,.08);color:#b45309}.bcp-diag-state.is-blocked,.bcp-diag-state.is-failed{border-color:rgba(220,38,38,.3);background:rgba(220,38,38,.07);color:#b91c1c}.bcp-diagnostic-list strong{font-size:13px;color:var(--bcp-ink)}.bcp-diagnostic-list p{margin:3px 0 0;color:var(--bcp-muted);font-size:12px;line-height:1.45}
.bcp-glossary{margin-top:16px;padding:17px 18px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm)}.bcp-glossary h4{font-size:14px}.bcp-glossary dl{display:grid;grid-template-columns:150px 1fr;gap:8px 16px;margin:12px 0 0;font-size:12px}.bcp-glossary dt{font-weight:bold;color:var(--bcp-ink)}.bcp-glossary dd{margin:0;color:var(--bcp-muted);line-height:1.45}
.bcp-shell .bcp-docs-help{margin:16px 0 0;color:var(--bcp-muted);font-size:12px}.bcp-docs-help a{font-weight:bold;color:var(--bcp-accent-deep)}
.bcp-about-hero{padding:22px 24px;margin-bottom:16px;border:1px solid rgba(79,70,229,.24);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:linear-gradient(118deg,rgba(79,70,229,.09),var(--bcp-raised) 58%)}
.bcp-about-hero h4{margin:5px 0 6px;color:var(--bcp-ink);font-size:22px}.bcp-about-hero p{max-width:760px;margin:0;color:var(--bcp-muted);font-size:13px;line-height:1.55}.bcp-about-badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.bcp-about-badges .bcp-badge{display:inline-flex;width:auto;margin:0}
.bcp-about-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.bcp-about-section{padding:18px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-about-section h4{margin:0;color:var(--bcp-ink);font-size:15px}.bcp-about-section>p{margin:6px 0 14px;color:var(--bcp-muted);font-size:12.5px;line-height:1.5}.bcp-about-details{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0}.bcp-about-details>div{padding:10px 11px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-soft)}.bcp-about-details dt{margin:0 0 3px;color:var(--bcp-faint);font-size:10px;font-weight:bold;letter-spacing:.06em;text-transform:uppercase}.bcp-about-details dd{margin:0;color:var(--bcp-ink);font-size:12px;overflow-wrap:anywhere}.bcp-about-links{display:flex;flex-wrap:wrap;gap:8px}.bcp-about-links .bcp-ico{margin-right:5px;vertical-align:-2px}
@media(max-width:840px){.bcp-about-grid,.bcp-about-details{grid-template-columns:1fr}}
.bcp-catalog-mode{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.8fr);gap:18px;align-items:end;margin:0 0 16px;padding:16px 18px;border:1px solid var(--bcp-line);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}.bcp-catalog-mode h4{margin:5px 0;font-size:14px;color:var(--bcp-ink)}.bcp-catalog-mode p{margin:0;color:var(--bcp-muted);font-size:12px;line-height:1.5}.bcp-catalog-mode-form{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.bcp-catalog-mode-form label{width:100%;font-size:11px;font-weight:bold;color:var(--bcp-muted)}.bcp-catalog-mode-select{flex:1 1 240px;min-width:0}
/* Wizard "Configuração rápida" (Setup) */
.bcp-setup-grid{display:grid;grid-template-columns:minmax(180px,260px) 1fr;gap:28px}
@media(max-width:720px){.bcp-setup-grid{grid-template-columns:1fr;gap:18px}}
.bcp-setup-extras{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:16px;align-items:start}
.bcp-setup-extras>.bcp-card{min-width:0}
.bcp-setup-extras>.bcp-card:only-child{grid-column:1/-1}
.bcp-setup-extras .bcp-card-bd{display:flex;flex-direction:column;gap:16px;padding-top:16px}
.bcp-setup-extras .bcp-card-bd>p,.bcp-setup-extras .bcp-card-bd>form{margin:0}
.bcp-setup-extras .bcp-actions{align-items:center}
@media(max-width:840px){.bcp-setup-extras{grid-template-columns:1fr}}
.bcp-setup-connect>.bcp-setup-h{margin-bottom:14px}
.bcp-setup-current{width:100%;max-width:720px;padding:18px;border:1px solid var(--bcp-line);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:var(--bcp-raised)}
.bcp-setup-current.is-valid{border-left-color:#16a34a}.bcp-setup-current.is-invalid{border-left-color:#dc2626}.bcp-setup-current.is-unavailable{border-left-color:#d97706}.bcp-setup-current-head{display:flex;gap:12px;align-items:center}.bcp-setup-current-head h4{margin:4px 0 0;color:var(--bcp-ink);font-size:17px;font-weight:bold}.bcp-setup-current-head>.bcp-badge{margin-left:auto}
.bcp-setup-current-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:29px;height:29px;border:1px solid rgba(22,163,74,.28);border-radius:50%;background:rgba(22,163,74,.08);color:#15803d;font-size:14px;font-weight:bold}.bcp-setup-current.is-invalid .bcp-setup-current-icon{border-color:rgba(220,38,38,.3);background:rgba(220,38,38,.08);color:#b91c1c}.bcp-setup-current.is-unavailable .bcp-setup-current-icon{border-color:rgba(217,119,6,.3);background:rgba(217,119,6,.08);color:#b45309}.bcp-setup-current-icon .bcp-ico{display:block;width:14px;height:14px;margin:0;vertical-align:middle}
.bcp-setup-current dl{display:grid;grid-template-columns:minmax(150px,.7fr) minmax(260px,1.3fr);gap:10px;margin:18px 0 0}.bcp-setup-current dl>div{padding:11px 12px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);background:var(--bcp-soft)}.bcp-setup-current dt{margin:0 0 4px;color:var(--bcp-faint);font-size:10.5px;font-weight:bold;letter-spacing:.06em;text-transform:uppercase}.bcp-setup-current dd{margin:0;color:var(--bcp-muted);font-size:12px;line-height:1.45}.bcp-setup-current dd code{color:var(--bcp-ink)}
.bcp-setup-current .bcp-actions{margin-top:15px}.bcp-setup-current .bcp-actions form{margin:0}
@media(max-width:720px){.bcp-setup-current-head{align-items:flex-start;flex-wrap:wrap}.bcp-setup-current-head>.bcp-badge{margin-left:41px}.bcp-setup-current dl{grid-template-columns:1fr}}
.bcp-setup-h{
  font-size:11px;font-weight:bold;letter-spacing:.08em;
  text-transform:uppercase;color:var(--bcp-faint);margin:0 0 10px;
}
.bcp-pf{list-style:none;margin:0;padding:0}
.bcp-pf li{
  display:flex;align-items:center;gap:8px;font-size:13px;
  padding:7px 0;border-bottom:1px dotted var(--bcp-line);
}
.bcp-pf li:last-child{border-bottom:0}
.bcp-pf li em{
  margin-left:auto;font-style:normal;font-family:var(--bcp-mono);
  font-size:11.5px;color:var(--bcp-faint);
}
.bcp-pf-ok .bcp-ico{color:#16a34a}
.bcp-pf-x{
  display:inline-flex;align-items:center;justify-content:center;
  width:16px;height:16px;border-radius:50%;background:var(--bcp-err);
  color:#fff;font-size:11px;font-weight:bold;flex:0 0 auto;
}
.bcp-setup-form{display:flex;flex-direction:column;align-items:flex-start;margin:0}
.bcp-setup-lbl{
  display:block;font-size:13px;font-weight:bold;color:var(--bcp-ink);
  margin:0 0 8px;
}
.bcp-setup-input{
  display:block;
  width:100%;max-width:420px;font-family:var(--bcp-mono);font-size:13px;
  padding:9px 11px;border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-raised);
  color:var(--bcp-ink);margin:0 0 9px;
}
.bcp-setup-hint,.bcp-setup-note{
  font-size:12.5px;color:var(--bcp-faint);margin:0;max-width:520px;line-height:1.5;
}
.bcp-vibe-diagnostic{font-size:12.5px;color:var(--bcp-muted);margin:6px 0;line-height:1.5;overflow-wrap:anywhere}
.bcp-vibe-diagnostic strong{color:var(--bcp-ink)}
.bcp-vibe-sync-result{flex-basis:100%}
.bcp-setup-note{margin-top:9px}
.bcp-setup-note code,.bcp-setup-hint code{
  font-family:var(--bcp-mono);font-size:12px;
}
.bcp-flash{
  display:flex;gap:10px;align-items:flex-start;padding:12px 14px;
  border-radius:var(--bcp-r-sm);margin:0 0 16px;font-size:13px;
}
.bcp-flash .bcp-ico{flex:0 0 auto;margin-top:1px}
.bcp-flash-sub{font-size:12.5px;color:var(--bcp-faint);margin-top:5px}
.bcp-flash-ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.30)}
.bcp-flash-ok .bcp-ico{color:#16a34a}
.bcp-flash-no{background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.28)}
.bcp-flash-warn{
  background:var(--bcp-raised);border:1px solid var(--bcp-line);
  align-items:center;justify-content:space-between;
}
.bcp-flash-warn .btn{flex:0 0 auto;margin-left:12px}
.bcp-server-context{align-items:center}
.bcp-server-context>div:first-child{min-width:0}
.bcp-server-context .bcp-actions{display:flex;align-items:end;gap:8px;margin:0 0 0 auto;flex-wrap:wrap}
.bcp-server-context .bcp-fg-c{min-width:250px}
.bcp-server-context-card{
  display:inline-grid;grid-template-columns:minmax(150px,auto) auto;
  align-items:center;gap:10px 12px;max-width:100%;box-sizing:border-box;
  margin:0;padding:9px 10px;border:1px solid var(--bcp-line);
  border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);
  background:transparent;color:var(--bcp-muted);line-height:1.2;
}
.bcp-server-context-details{display:grid;gap:3px;min-width:0}
.bcp-server-context-label{
  color:var(--bcp-faint);font-size:9.5px;font-weight:bold;
  letter-spacing:.09em;text-transform:uppercase;
}
.bcp-server-context-identity{display:flex;align-items:center;gap:7px;min-width:0}
.bcp-server-context-name{overflow:hidden;color:var(--bcp-ink);font-size:13px;font-weight:bold;text-overflow:ellipsis;white-space:nowrap}
.bcp-server-context-environment.bcp-badge{height:21px;margin:0;padding:0 8px;font-size:10px}
.bcp-server-context-environment.bcp-badge::before{width:5px;height:5px;box-shadow:0 0 0 2px rgba(139,147,161,.14)}
.bcp-server-context-environment.is-sandbox{border-color:rgba(79,70,229,.30);background:rgba(79,70,229,.08);color:var(--bcp-accent-deep)}
.bcp-server-context-environment.is-sandbox::before{background:var(--bcp-accent);box-shadow:0 0 0 2px rgba(79,70,229,.18)}
.bcp-server-context-environment.is-production{border-color:rgba(22,163,74,.24);background:rgba(22,163,74,.07);color:#15803d}
.bcp-server-context-environment.is-production::before{background:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.14)}
.bcp-server-context-host{
  display:block;overflow:hidden;padding:0;border:0;background:transparent;
  color:var(--bcp-faint);font-family:var(--bcp-mono);font-size:10.5px;
  line-height:1.25;text-overflow:ellipsis;white-space:nowrap;
}
.bcp-server-context-action{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap}
@media(min-width:992px){
  .bcp-overview-top .bcp-server-context-card{
    grid-template-columns:minmax(0,1fr);grid-template-rows:1fr auto;
    align-content:center;padding:16px;
  }
  .bcp-overview-top .bcp-server-context-action{
    grid-column:1/-1;width:100%;margin-top:8px;
  }
}
@media(max-width:991px){
  .bcp-overview-top{gap:12px}
  .bcp-overview-top>[class*="col-"]{width:100%}
}
@media(max-width:640px){
  .bcp-server-context-card{display:grid;grid-template-columns:minmax(0,1fr)}
  .bcp-server-context-action{grid-column:1/-1;width:100%}
}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.bcp-tbl-wrap{width:100%;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
.bcp-shell :is(a,button,input,select,textarea):focus-visible{outline:2px solid var(--bcp-accent);outline-offset:2px}
.bcp-tbl{width:100%;border-collapse:collapse;margin-top:6px}
.bcp-tbl th{
  text-align:left;font-size:11px;font-weight:bold;letter-spacing:.08em;
  text-transform:uppercase;color:var(--bcp-faint);
  padding:8px 10px;border-bottom:1px dotted var(--bcp-line);
}
.bcp-tbl td{
  padding:12px 10px;border-bottom:1px dotted var(--bcp-line);
  font-size:13px;vertical-align:top;
}
.bcp-tbl tr:last-child td{border-bottom:0}
.bcp-plan-release{margin:6px 0 0}
/* slug do combo: sob o nome, alinhado à ESQUERDA (o .bcp-row-v
   compartilhado é text-align:right → jogava o slug pra direita,
   "solto" embaixo). Tight, mono, discreto. */
.bcp-csub{
  margin-top:3px;font-family:var(--bcp-mono);font-size:12px;
  color:var(--bcp-faint);letter-spacing:-.01em;text-align:left;
}
/* Valor atual do WHMCS sob o preço da plataforma quando há
   divergência no ciclo (vermelho, discreto). */
.bcp-cwhmcs{
  margin-top:2px;font-size:11.5px;font-weight:bold;
  color:var(--bcp-err);white-space:nowrap;
}
/* Tabs dos 3 conteúdos do addon (Combos/Sync/Preços). Server-
   rendered: links por action, aba ativa = sublinhado + accent. */
.bcp-tabs{
  display:flex;gap:4px;margin:0 0 18px;
  border-bottom:1px solid var(--bcp-line);
}
.bcp-shell .bcp-tabs a.bcp-tab{
  display:inline-flex;align-items:center;gap:5px;
  padding:9px 14px;font-size:13.5px;font-weight:bold;
  color:var(--bcp-muted);text-decoration:none;
  border-bottom:2px solid transparent;margin-bottom:-1px;
  transition:color .12s,border-color .12s;
}
.bcp-shell .bcp-tabs a.bcp-tab:hover{color:var(--bcp-ink)}
.bcp-shell .bcp-tabs a.bcp-tab.is-active{
  color:var(--bcp-accent-deep);
  border-bottom-color:var(--bcp-accent);
}
/* mata o box/anel azul que o tema do WHMCS desenha no <a> ao
   clicar (mouse); foco de TECLADO segue acessível e discreto. */
.bcp-shell .bcp-tabs a.bcp-tab:focus,
.bcp-shell .bcp-tabs a.bcp-tab:active{
  outline:none;box-shadow:none;
}
.bcp-shell .bcp-tabs a.bcp-tab:focus-visible{
  outline:2px solid var(--bcp-accent);outline-offset:-2px;
}
.bcp-tabs .bcp-ico{opacity:.85}
/* Feedback após a ação: os <div class="bcp-badge"> (topo da view) viram
   BANNER proeminente de largura total — não passam despercebidos. Os
   <span class="bcp-badge"> inline continuam chips pequenos. */
.bcp-addon div.bcp-badge{
  display:flex;width:100%;box-sizing:border-box;height:auto;
  align-items:center;gap:9px;padding:11px 14px;margin:0 0 14px;
  border-radius:var(--bcp-r-sm);font-size:13.5px;font-weight:bold;
  line-height:1.4;
}
/* Aba Planos: alinha ao MEIO + colunas de largura FIXA (a coluna
   Recursos alinha entre grupos, independe do tamanho do nome do plano). */
.bcp-tbl.bcp-plans{table-layout:fixed}
.bcp-addon .bcp-tbl.bcp-plans td{vertical-align:middle}
.bcp-tbl.bcp-plans th:first-child,.bcp-tbl.bcp-plans td:first-child{width:200px}
.bcp-tbl.bcp-plans th:last-child,.bcp-tbl.bcp-plans td:last-child{width:170px}
.bcp-plan-group-note{
  display:flex;align-items:baseline;flex-wrap:wrap;gap:5px 8px;
  margin:0 0 8px;padding:8px 11px;border:1px solid rgba(180,83,9,.22);
  border-radius:var(--bcp-r-sm);background:rgba(180,83,9,.05);
  color:var(--bcp-warn);font-size:12px;line-height:1.45;
}
.bcp-plan-group-note strong{font-weight:bold}
.bcp-plan-group-note span{color:var(--bcp-muted)}
.bcp-plan-group-note ul{
  flex:1 0 100%;margin:1px 0 0;padding-left:18px;color:var(--bcp-muted);
}
.bcp-plan-group-note li+li{margin-top:2px}
.bcp-addon .bcp-plan-status{
  text-align:right;white-space:normal;overflow-wrap:anywhere;
}
.bcp-plan-status-stack{
  display:inline-flex;flex-direction:column;align-items:flex-end;
  gap:4px;max-width:100%;
}
.bcp-plan-status-link,.bcp-plan-import{
  display:inline-flex;justify-content:flex-end;max-width:100%;
}
.bcp-plan-product-link{
  color:var(--bcp-muted);font-size:11px;text-decoration:none;
  border-bottom:1px dotted var(--bcp-faint);
}
.bcp-plan-product-link:hover{color:var(--bcp-accent-deep)}
.bcp-plan-status .bcp-badge{
  box-sizing:border-box;max-width:100%;white-space:normal;
  text-align:center;line-height:1.25;
}
/* Mobile: as abas viram SCROLL horizontal (não quebram) e as tabelas
   largas rolam dentro do card em vez de estourar o layout. */
.bcp-tabs{overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch}
.bcp-shell .bcp-tabs a.bcp-tab{white-space:nowrap;flex:0 0 auto}
@media(max-width:720px){
  .bcp-addon .bcp-card-bd{overflow:visible}
  .bcp-tbl{min-width:520px}
  .bcp-card-bd>.bcp-tbl{display:block;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .bcp-overview-hero{grid-template-columns:1fr;padding:18px}
  .bcp-overview-score{min-height:76px;flex-direction:row;gap:8px;justify-content:flex-start;padding:0 14px}
  .bcp-overview-score span{margin:0}
  .bcp-overview-status,.bcp-operation-grid,.bcp-overview-context{grid-template-columns:1fr}
  .bcp-operation-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bcp-operation-filters,.bcp-catalog-mode{grid-template-columns:1fr}
  .bcp-operation-filters .btn{justify-self:start}
  .bcp-status-card{min-height:0}
  .bcp-onboarding{padding:16px}
  .bcp-onboarding-step{grid-template-columns:28px minmax(0,1fr)}
  .bcp-onboarding-step .btn{grid-column:2;justify-self:start}
  .bcp-diagnostic-list li{flex-direction:column;gap:7px}
  .bcp-diagnostic-list li>.btn{margin-left:0}
  .bcp-glossary dl{grid-template-columns:1fr;gap:3px}
}
@media(min-width:721px) and (max-width:1050px){
  .bcp-overview-status{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bcp-operation-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bcp-operation-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
  .bcp-operation-filters{grid-template-columns:repeat(3,minmax(0,1fr))}
}

/* Client-area settings reuse the shared field and button styles. */
.bcp-experiences{display:grid;gap:24px}
.bcp-experience-context{display:flex;align-items:center;gap:9px;flex-wrap:wrap;font-size:12px;color:var(--bcp-muted)}
.bcp-experience-context strong{color:var(--bcp-ink)}
.bcp-experience-features{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.bcp-experience-feature{display:flex;flex-direction:column;gap:10px;min-width:0;padding:16px 18px;border:1px solid var(--bcp-line);border-left:3px solid var(--bcp-accent);border-radius:var(--bcp-r-sm);background:var(--bcp-surface)}
.bcp-experience-feature-head,.bcp-experience-policy-head{display:flex;align-items:center;gap:12px}
.bcp-experience-icon{display:inline-flex;align-items:center;justify-content:center;flex:none;width:34px;height:34px;border-radius:var(--bcp-r-sm);background:#eeedff;color:var(--bcp-accent)}
.bcp-shell .bcp-experience-icon .bcp-ico{display:block;flex:none;width:20px;height:20px;margin:0;vertical-align:middle}
.bcp-experiences h4{font-size:16px;font-weight:700;line-height:1.4;margin:0}
.bcp-experience-description{font-size:13px;line-height:1.6;color:var(--bcp-muted);margin:0}
.bcp-experience-details{font-size:12px;line-height:1.6;color:var(--bcp-muted)}
.bcp-experience-details summary{cursor:pointer;color:var(--bcp-accent);font-weight:600}
.bcp-experiences .bcp-experience-details p{margin:10px 0 0}
.bcp-experience-feature-form{margin-top:auto;padding-top:2px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 14px}
.bcp-experience-feature-form .bcp-experience-toggle{flex:1 1 160px}
.bcp-experience-feature-form .btn{flex:none}
.bcp-shell .bcp-experience-toggle{display:flex;align-items:center;gap:10px;font-size:12px;line-height:1.5;cursor:pointer;margin:0}
.bcp-shell .bcp-experience-toggle input{appearance:none;position:relative;flex:0 0 32px;width:32px;height:18px;margin:0;border:1px solid #cbd0d9;border-radius:20px;background:#e6e8ec;cursor:pointer;transition:background .15s}
.bcp-experience-toggle input::before{content:"";position:absolute;left:2px;top:2px;width:12px;height:12px;border-radius:50%;background:#fff;box-shadow:0 1px 2px #0002;transition:transform .15s}
.bcp-shell .bcp-experience-toggle input:checked{background:var(--bcp-accent);border-color:var(--bcp-accent)}
.bcp-experience-toggle input:checked::before{transform:translateX(14px)}
.bcp-experience-policy{border:1px solid var(--bcp-line);border-radius:var(--bcp-r);overflow:hidden}
.bcp-experience-policy-head{padding:22px 24px;background:var(--bcp-raised);border-bottom:1px solid var(--bcp-line)}
.bcp-experiences .bcp-experience-policy-head p{font-size:12px;line-height:1.6;color:var(--bcp-muted);margin:5px 0 0}
.bcp-experience-section{min-width:0;margin:0;padding:24px;border:0;border-bottom:1px solid var(--bcp-line)}
.bcp-experience-section legend{float:left;width:100%;padding:0;margin:0 0 16px;border:0;font-size:14px;font-weight:700;line-height:1.4;color:var(--bcp-ink)}
.bcp-experience-section legend+*{clear:both}
.bcp-experience-fields{display:grid;grid-template-columns:minmax(240px,2fr) repeat(2,minmax(160px,1fr));gap:18px;align-items:start;max-width:1100px}
.bcp-experience-field{min-width:0}
.bcp-experience-field .bcp-fg-l{font-size:12px;font-weight:500;color:var(--bcp-muted);line-height:1.5}
.bcp-experiences .bcp-experience-help{font-size:12px;line-height:1.6;color:var(--bcp-muted);margin:12px 0 0;max-width:1000px}
.bcp-experience-section>legend+.bcp-experiences .bcp-experience-help{margin:0 0 18px}
.bcp-experience-product-group+.bcp-experience-product-group{margin-top:22px;padding-top:20px;border-top:1px dashed var(--bcp-line)}
.bcp-experience-product-group h5{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;margin:0 0 12px;line-height:1.5}
.bcp-experience-count{display:inline-flex;align-items:center;justify-content:center;min-width:23px;height:23px;border-radius:6px;padding:0 6px;background:var(--bcp-raised);color:var(--bcp-muted);font-size:11px;font-weight:500}
.bcp-experience-products{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}
.bcp-shell .bcp-experience-product{display:flex;align-items:center;gap:10px;min-width:0;margin:0;padding:12px;border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);cursor:pointer;transition:border-color .15s,background .15s}
.bcp-experience-product:hover{background:var(--bcp-raised)}
.bcp-experience-product:has(input:checked){background:#f5f4ff;border-color:#d8d5ff}
.bcp-experience-product input{width:15px;height:15px;flex:none;margin:0;accent-color:var(--bcp-accent)}
.bcp-experience-product strong{font-size:13px;font-weight:600;overflow-wrap:anywhere}
.bcp-experience-product small{display:block;font-size:11px;color:var(--bcp-muted);line-height:1.5;margin-top:4px}
.bcp-experience-product-id{margin-left:auto;font-size:11px;color:var(--bcp-faint);flex:none}
.bcp-experience-empty{padding:18px;border-radius:var(--bcp-r-sm);background:var(--bcp-raised);font-size:13px;color:var(--bcp-muted)}
.bcp-experience-policy-footer{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:20px 24px;background:var(--bcp-raised)}
.bcp-experience-policy-footer p{font-size:12px;line-height:1.6;color:var(--bcp-muted);max-width:760px;margin:0}
.bcp-experience-policy-footer .btn{flex:none}
.bcp-experiences [hidden]{display:none!important}
@media(max-width:1100px){.bcp-experience-features{grid-template-columns:1fr}.bcp-experience-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.bcp-experience-fields,.bcp-experience-products{grid-template-columns:1fr}.bcp-experience-policy-footer{flex-direction:column;align-items:flex-start}.bcp-experience-section,.bcp-experience-policy-head,.bcp-experience-policy-footer{padding:18px}}

/* Preços: linha de cabeçalho de CATEGORIA (full-width) na tabela. */
.bcp-addon tr.bcp-price-group td{
  font-size:11px;font-weight:bold;letter-spacing:.08em;
  text-transform:uppercase;color:var(--bcp-faint);
  padding:22px 10px 8px;border-bottom:1px solid var(--bcp-line);
}
/* input/select/textarea/.bcp-pick/.bcp-fg = FONTE ÚNICA no Assets.
   botões = Bootstrap (btn-*). Aqui só o que é exclusivo do addon. */
.bcp-mode-select{flex:1 1 420px;max-width:620px;min-width:260px}
.bcp-hr{border:0;border-top:1px dashed var(--bcp-line);margin:18px 0}
.bcp-prodctx{
  background:var(--bcp-raised);border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);padding:14px 16px;margin-bottom:18px;
}
.bcp-prodctx .bcp-hr{display:none}
/* Marketplace — cards de resumo por moeda + pager. */
.bcp-mkt-sums{
  display:flex;flex-wrap:wrap;gap:12px;margin:4px 0 14px;
}
.bcp-mkt-sum{
  flex:1 1 180px;min-width:170px;background:var(--bcp-raised);
  border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);
  padding:12px 14px;
}
.bcp-mkt-sum-accent{border-left:3px solid var(--bcp-accent)}
.bcp-mkt-sum-h{
  font-size:13px;font-weight:bold;color:var(--bcp-ink);
  margin-bottom:8px;
}
.bcp-mkt-sum-r{
  display:flex;justify-content:space-between;gap:10px;
  font-size:12.5px;padding:3px 0;color:var(--bcp-faint);
}
.bcp-mkt-sum-r strong{color:var(--bcp-ink);font-weight:bold}
.bcp-mkt-pager{
  align-items:center;gap:10px;margin-top:14px;
}
.bcp-mkt-pager .bcp-row-v{margin-right:auto}
/* Marketplace unificado: pedidos (corpo) + números (rail à direita). */
.bcp-mkt-layout{
  display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);
  gap:22px;align-items:start;
}
.bcp-mkt-main{min-width:0}
.bcp-mkt-rail{
  display:flex;flex-direction:column;
  padding-left:20px;border-left:1px solid var(--bcp-line);
}
.bcp-mkt-rail .bcp-sec{margin-top:16px}
.bcp-mkt-rail .bcp-sec:first-child{margin-top:0}
.bcp-mkt-rail .bcp-mkt-sums{flex-direction:column;margin-top:4px}
.bcp-mkt-rail .bcp-mkt-sum{flex:1 1 auto;min-width:0}
.bcp-mkt-bar{
  height:6px;border-radius:6px;background:var(--bcp-line);
  overflow:hidden;margin:2px 0 8px;
}
.bcp-mkt-bar>i{display:block;height:100%;background:var(--bcp-accent);border-radius:6px}
@media(max-width:900px){
  .bcp-mkt-layout{grid-template-columns:1fr;gap:6px}
  .bcp-mkt-rail{
    padding-left:0;border-left:0;border-top:1px solid var(--bcp-line);
    padding-top:12px;margin-top:8px;
  }
  .bcp-mkt-rail .bcp-mkt-sums{flex-direction:row}
  .bcp-operation-evidence-grid{grid-template-columns:1fr}
}
/* Webhooks: caixa do segredo exibido UMA vez (copiável, destacada). */
.bcp-wh-secret{
  margin-top:10px;padding:10px 12px;background:var(--bcp-raised);
  border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);
  overflow-x:auto;
}
.bcp-wh-secret code{
  font-family:var(--bcp-mono);font-size:13px;color:var(--bcp-ink);
  user-select:all;white-space:nowrap;
}
CSS;
    }
}
