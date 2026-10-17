<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Design system BladeCP — UMA folha de estilo, ESCOPADA em
 * `.bcp-shell` (todo seletor é prefixado/aninhado → não vaza no tema
 * do hoster nem sofre dele). Zero dependência externa (sem CDN, sem
 * web font: OSS roda offline; o caráter vem de escala/tracking/
 * contraste mono×sans, não de fonte importada). Reusada por admin
 * e client area. Acessível: focus-visible, contraste,
 * prefers-reduced-motion.
 */
final class Assets
{
    public static function css(): string
    {
        return <<<'CSS'
.bcp-shell{
  --bcp-ink:#0b0f17;--bcp-surface:#fff;--bcp-raised:#f7f8fb;
  --bcp-line:#e6e8ec;--bcp-muted:#5b6472;--bcp-faint:#8b93a1;
  --bcp-accent:#4f46e5;--bcp-accent-deep:#4338ca;
  --bcp-ok:#4338ca;--bcp-warn:#b45309;--bcp-err:#dc2626;
  --bcp-r:14px;--bcp-r-sm:9px;
  --bcp-mono:monospace;
  --bcp-sh:0 1px 2px rgba(11,15,23,.04),
    0 8px 24px -12px rgba(11,15,23,.14);
  box-sizing:border-box;display:block;color:var(--bcp-ink);
  font-family:inherit;font-size:14px;line-height:1.5;
  font-weight:normal;
  -webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;
}
.bcp-shell *,.bcp-shell *::before,.bcp-shell *::after{box-sizing:border-box}
.bcp-shell svg{display:block}
/* Neutraliza o tema do WHMCS vazando pra dentro do .bcp-shell
   (negrito exagerado, code rosa, links coloridos nos botões). */
.bcp-shell h1,.bcp-shell h2,.bcp-shell h3,.bcp-shell h4{
  font-weight:bold;margin:0;color:var(--bcp-ink);
}
.bcp-shell p{margin:0;font-weight:normal}
.bcp-shell strong,.bcp-shell b{font-weight:bold}
.bcp-shell label{margin:0;font-weight:normal;display:block}
.bcp-shell a:not(.btn){color:inherit;text-decoration:none}
.bcp-shell code{
  font-family:var(--bcp-mono);font-size:12px;font-weight:normal;
  color:var(--bcp-faint);background:none;padding:0;border:0;
}
.bcp-shell input,.bcp-shell select,.bcp-shell textarea{
  font-family:inherit;font-weight:normal;color:var(--bcp-ink);
}
.bcp-shell table{font-weight:normal}

.bcp-head{
  display:flex;align-items:center;justify-content:space-between;
  gap:16px;flex-wrap:wrap;margin:2px 0 16px;
}
.bcp-logo{display:inline-flex;align-items:center;color:var(--bcp-ink)}
.bcp-logo-mark{flex:none;display:block;height:auto;max-width:100%}

.bcp-badge{
  display:inline-flex;align-items:center;gap:7px;height:28px;
  padding:0 12px;border-radius:999px;font-size:12px;font-weight:normal;
  letter-spacing:.01em;border:1px solid var(--bcp-line);
  background:var(--bcp-raised);color:var(--bcp-muted);
}
.bcp-badge::before{
  content:"";width:7px;height:7px;border-radius:50%;
  background:var(--bcp-faint);box-shadow:0 0 0 3px rgba(139,147,161,.16);
}
.bcp-badge.is-ok{color:var(--bcp-accent-deep);
  border-color:rgba(79,70,229,.30);background:rgba(79,70,229,.08)}
.bcp-badge.is-ok::before{background:var(--bcp-accent);
  box-shadow:0 0 0 3px rgba(79,70,229,.20)}
.bcp-badge.is-err{color:var(--bcp-err);
  border-color:rgba(220,38,38,.28);background:rgba(220,38,38,.07)}
.bcp-badge.is-err::before{background:var(--bcp-err);
  box-shadow:0 0 0 3px rgba(220,38,38,.18)}

/* Lista de componentes (nome + versão + saúde). Dot
   verde = saudável (semântico, distinto do accent da marca). */
.bcp-comps{display:flex;flex-wrap:wrap;gap:6px 14px;justify-content:flex-end}
.bcp-comp{
  display:inline-flex;align-items:center;gap:6px;
  font-size:12.5px;color:var(--bcp-ink);white-space:nowrap;
}
.bcp-dot{
  width:7px;height:7px;border-radius:50%;flex:none;
  background:var(--bcp-faint);
}
.bcp-dot.is-ok{
  background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.16);
}
.bcp-dot.is-err{
  background:var(--bcp-err);box-shadow:0 0 0 3px rgba(220,38,38,.18);
}

.bcp-card{
  background:var(--bcp-surface);border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r);box-shadow:var(--bcp-sh);overflow:hidden;
}
.bcp-card-hd{
  display:flex;align-items:center;gap:12px;
  padding:16px 20px;border-bottom:1px solid var(--bcp-line);
  position:relative;
}
/* Logo sempre alinhado à direita da linha do cabeçalho. */
.bcp-shell .bcp-card-hd .bcp-logo{margin-left:auto;flex:none}
/* logo BladeCP na cor índigo escuro da marca (SVG = currentColor) */
.bcp-shell .bcp-logo{color:var(--bcp-accent-deep)}
.bcp-card-hd::before{
  content:"";position:absolute;left:0;top:14px;bottom:14px;width:3px;
  border-radius:0 3px 3px 0;background:var(--bcp-accent);
}
.bcp-card-hd h3{
  margin:0;font-size:15.5px;font-weight:bold;
  letter-spacing:-.01em;color:var(--bcp-ink);
}
.bcp-card-hd p{
  margin:3px 0 0;font-size:12.5px;color:var(--bcp-muted);
}
.bcp-card-bd{padding:6px 20px 18px}

.bcp-sec{
  font-size:11px;font-weight:bold;letter-spacing:.09em;
  text-transform:uppercase;color:var(--bcp-faint);
  margin:18px 0 4px;padding-top:14px;border-top:1px dashed var(--bcp-line);
}
.bcp-sec:first-child{border-top:0;padding-top:6px;margin-top:8px}

.bcp-grid{
  display:grid;grid-template-columns:1fr 1fr;
  column-gap:32px;row-gap:2px;
}
.bcp-context-grid{
  grid-template-columns:repeat(3,minmax(0,1fr));column-gap:0;
}
.bcp-context-grid .bcp-row{
  padding-left:24px;padding-right:24px;border-bottom:0;
}
.bcp-context-grid .bcp-row:first-child{padding-left:0}
.bcp-context-grid .bcp-row:last-child{padding-right:0}
.bcp-context-grid .bcp-row+.bcp-row{border-left:1px dashed var(--bcp-line)}
@media (max-width:900px){
  .bcp-context-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bcp-context-grid .bcp-row{
    padding-left:0;padding-right:0;border-left:0;
  }
  .bcp-context-grid .bcp-row:nth-child(odd){padding-right:24px}
  .bcp-context-grid .bcp-row:nth-child(even){
    padding-left:24px;border-left:1px dashed var(--bcp-line);
  }
}
@media (max-width:760px){
  .bcp-grid,.bcp-context-grid{grid-template-columns:1fr}
  .bcp-context-grid .bcp-row,
  .bcp-context-grid .bcp-row:nth-child(odd),
  .bcp-context-grid .bcp-row:nth-child(even){
    padding-left:0;padding-right:0;border-left:0;
  }
}

.bcp-row{
  display:flex;align-items:baseline;justify-content:space-between;
  gap:16px;padding:11px 0;border-bottom:1px solid var(--bcp-line);
  opacity:0;transform:translateY(5px);
  animation:bcp-in .42s cubic-bezier(.22,1,.36,1) forwards;
  animation-delay:calc(var(--bcp-i,0) * 45ms + 40ms);
}
.bcp-row:last-child{border-bottom:0}
.bcp-row-k{
  display:flex;align-items:center;gap:6px;color:var(--bcp-muted);
  font-size:12.5px;font-weight:normal;flex:none;
}
.bcp-row-v{
  text-align:right;font-size:13px;font-weight:normal;
  word-break:break-word;min-width:0;
}
.bcp-row-v.mono{
  font-family:var(--bcp-mono);font-size:12.5px;font-weight:normal;
  letter-spacing:-.01em;
}
.bcp-row-v.muted{color:var(--bcp-faint);font-weight:normal}

.bcp-pill{
  display:inline-flex;align-items:center;gap:6px;height:22px;
  padding:0 9px;border-radius:7px;font-size:11.5px;font-weight:normal;
  background:var(--bcp-raised);border:1px solid var(--bcp-line);
}
.bcp-pill.on{color:var(--bcp-accent-deep);
  background:rgba(79,70,229,.09);border-color:rgba(79,70,229,.26)}
.bcp-pill.off{color:var(--bcp-faint)}
.bcp-pill .bcp-pill-v{
  font-size:10.5px;font-weight:bold;color:var(--bcp-accent-deep);
  background:rgba(79,70,229,.10);border-radius:5px;padding:1px 5px;
}

.bcp-tip{position:relative;display:inline-flex}
.bcp-tip>b{
  width:15px;height:15px;border-radius:50%;cursor:help;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:10px;font-weight:bold;color:var(--bcp-faint);
  border:1px solid var(--bcp-line);background:var(--bcp-surface);
}
.bcp-tip-pop{
  position:absolute;left:0;top:calc(100% + 7px);z-index:30;
  width:240px;padding:9px 11px;border-radius:var(--bcp-r-sm);
  background:var(--bcp-ink);color:#eef0f3;font-size:11.5px;
  line-height:1.45;font-weight:normal;
  box-shadow:0 10px 30px -10px rgba(11,15,23,.5);
  opacity:0;visibility:hidden;transform:translateY(-3px);
  transition:opacity .14s,transform .14s,visibility .14s;
  pointer-events:none;
}
.bcp-tip:hover .bcp-tip-pop,
.bcp-tip:focus-within .bcp-tip-pop{
  opacity:1;visibility:visible;transform:translateY(0);
}
.bcp-tip>b:focus-visible{outline:2px solid var(--bcp-accent);outline-offset:2px}

.bcp-foot{
  display:flex;align-items:center;gap:10px;flex-wrap:wrap;
  margin-top:16px;padding-top:14px;border-top:1px solid var(--bcp-line);
  font-size:11.5px;color:var(--bcp-faint);
}
.bcp-foot .bcp-logo{opacity:.65}

.bcp-badge.is-warn{color:var(--bcp-warn);
  border-color:rgba(180,83,9,.28);background:rgba(180,83,9,.08)}
.bcp-badge.is-warn::before{background:var(--bcp-warn);
  box-shadow:0 0 0 3px rgba(180,83,9,.18)}

.bcp-hero{
  display:flex;align-items:center;justify-content:space-between;
  gap:14px;flex-wrap:wrap;
  padding:20px;border-bottom:1px solid var(--bcp-line);
  background:linear-gradient(135deg,rgba(79,70,229,.07),transparent 58%);
}
.bcp-hero-copy{
  display:flex;flex:1 1 260px;flex-direction:column;align-items:flex-start;
  gap:3px;min-width:0;text-align:left;
}
.bcp-hero-copy>span{
  color:var(--bcp-accent-deep);font-size:10.5px;font-weight:bold;
  letter-spacing:.1em;text-transform:uppercase;
}
.bcp-hero-copy>small{color:var(--bcp-muted);font-size:12px}
.bcp-sr{
  position:absolute!important;width:1px!important;height:1px!important;
  padding:0!important;margin:-1px!important;overflow:hidden!important;
  clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important;
}
.bcp-hero-d{
  font-family:var(--bcp-mono);font-size:15px;font-weight:bold;
  letter-spacing:-.01em;word-break:break-all;
}
.bcp-hero-d a{
  color:inherit;text-decoration:none;
  border-bottom:1px solid var(--bcp-line);
}
.bcp-hero-d a:hover{border-color:var(--bcp-accent)}

.bcp-source{
  display:block;margin-top:4px;color:var(--bcp-faint);font-size:10.5px;
}
.bcp-section-heading{
  display:flex;align-items:flex-end;justify-content:space-between;
  gap:16px;margin-top:18px;border-top:1px dashed var(--bcp-line);
}
.bcp-section-heading .bcp-sec{
  margin:0;padding-top:14px;border-top:0;
}
.bcp-section-heading p{
  margin-top:2px;color:var(--bcp-muted);font-size:12px;
}
.bcp-health{
  display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
  color:var(--bcp-muted);font-size:11.5px;
}
.bcp-health::before{
  content:"";width:7px;height:7px;border-radius:50%;background:var(--bcp-faint);
  box-shadow:0 0 0 3px rgba(139,147,161,.14);
}
.bcp-health.is-ok{color:#15803d}
.bcp-health.is-ok::before{
  background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.14);
}
.bcp-health.is-err{color:var(--bcp-err)}
.bcp-health.is-err::before{
  background:var(--bcp-err);box-shadow:0 0 0 3px rgba(220,38,38,.14);
}
.bcp-component-grid{
  display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
  gap:8px;margin:10px 0 2px;
}
.bcp-component{
  display:flex;align-items:center;gap:8px;min-width:0;
  padding:10px 11px;border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-raised);
}
.bcp-component-name{
  min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  font-size:12.5px;
}
.bcp-component .bcp-pill{margin-left:auto;flex:none}
.bcp-empty-state{
  margin-top:10px;padding:11px 13px;border:1px dashed var(--bcp-line);
  border-radius:var(--bcp-r-sm);color:var(--bcp-muted);font-size:12px;
}
.bcp-usage-grid{
  display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
  gap:10px;margin-top:10px;
}
.bcp-usage-card{
  display:flex;flex-direction:column;gap:7px;min-width:0;
  padding:14px;border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-surface);
}
.bcp-usage-label{
  color:var(--bcp-muted);font-size:11px;font-weight:bold;
  letter-spacing:.06em;text-transform:uppercase;
}
.bcp-usage-card strong{font-size:16px;line-height:1.2}
.bcp-usage-card strong small{
  margin-left:5px;color:var(--bcp-muted);font-size:11.5px;font-weight:normal;
}
.bcp-usage-track{
  display:block;height:6px;overflow:hidden;border-radius:999px;
  background:#eceef3;
}
.bcp-usage-track>span{
  display:block;height:100%;border-radius:inherit;background:var(--bcp-accent);
}
.bcp-usage-card.is-warning .bcp-usage-track>span{background:var(--bcp-warn)}
.bcp-usage-card.is-critical .bcp-usage-track>span{background:var(--bcp-err)}
.bcp-usage-percent{color:var(--bcp-faint);font-size:10.5px}
.bcp-usage-alert{
  grid-column:1/-1;padding:9px 11px;border-radius:var(--bcp-r-sm);
  color:var(--bcp-warn);background:rgba(180,83,9,.08);
  border:1px solid rgba(180,83,9,.18);font-size:11.5px;
}
.bcp-activity{
  display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
  gap:8px;margin-top:9px;
}
.bcp-activity>div{
  display:grid;grid-template-columns:auto 1fr;gap:2px 9px;
  align-items:center;padding:11px 12px;border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-raised);
}
.bcp-activity-dot{
  grid-row:1/3;width:8px;height:8px;border-radius:50%;background:var(--bcp-faint);
}
.bcp-activity-dot.is-ok{background:#16a34a}
.bcp-activity span{font-size:11.5px;color:var(--bcp-muted)}
.bcp-activity strong{font-size:12px}

.bcp-actions{display:flex;gap:9px;flex-wrap:wrap}
.bcp-hero-actions{flex:none;margin-left:auto}
/* Botão índigo-claro (mesma família do badge is-ok / status
   ativo): texto índigo + fundo índigo translúcido + borda sutil.
   Ícone Lucide herda a cor (currentColor). */
.bcp-shell a.bcp-btn{
  display:inline-flex;align-items:center;gap:6px;
  padding:5px 11px;border-radius:var(--bcp-r-sm);
  font-size:12.5px;font-weight:bold;text-decoration:none;
  color:var(--bcp-accent-deep);
  background:rgba(79,70,229,.10);
  border:1px solid rgba(79,70,229,.22);
  transition:background .12s,border-color .12s;
}
.bcp-shell a.bcp-btn:hover,
.bcp-shell a.bcp-btn:focus{
  background:rgba(79,70,229,.16);
  border-color:rgba(79,70,229,.34);
  color:var(--bcp-accent-deep);text-decoration:none;
}
.bcp-shell a.bcp-btn .bcp-ico{opacity:.9}
/* Recursos do plano: chips compactos na mesma linha (pouco
   conteúdo → não ocupar 1/3 cada). Valor + rótulo lado a lado. */
.bcp-res{
  display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 2px;
  justify-content:center;
}
.bcp-res-c{
  display:inline-flex;align-items:baseline;gap:6px;
  padding:5px 11px;border:1px solid var(--bcp-line);
  border-radius:999px;background:var(--bcp-raised);
}
.bcp-res-v{font-size:13px;font-weight:bold;color:var(--bcp-ink)}
.bcp-res-k{
  font-size:10.5px;font-weight:bold;letter-spacing:.05em;
  text-transform:uppercase;color:var(--bcp-faint);
}
/* Botões = Bootstrap nativo do WHMCS admin (estrutura/tamanho/
   default/danger todos do BS). Único custom: o PRIMARY na nossa cor
   índigo da marca + negrito. Resto = Bootstrap puro, sem brigar. */
.bcp-shell .btn{font-weight:bold}
.bcp-shell .btn-primary{
  background-color:var(--bcp-accent);border-color:var(--bcp-accent);
  color:#fff;
}
.bcp-shell .btn-primary:hover,
.bcp-shell .btn-primary:focus,
.bcp-shell .btn-primary:active{
  background-color:var(--bcp-accent-deep);
  border-color:var(--bcp-accent-deep);color:#fff;
}

/* Formulário e seletor compartilhados pela interface administrativa. */
.bcp-fg{margin:14px 0;min-width:0}
/* label do campo = negrito simples. .bcp-shell .bcp-fg-l (0,2,0)
   vence o reset .bcp-shell label (0,1,1). */
.bcp-shell .bcp-fg-l{
  display:block;font-size:13px;font-weight:bold;color:var(--bcp-ink);
  margin:0 0 6px;
}
.bcp-fg-c{display:block}
.bcp-shell .bcp-fg-c input,
.bcp-shell .bcp-fg-c select,
.bcp-shell .bcp-fg-c textarea{
  /* !important: estes são os <select> nativos do WHMCS
     movidos pra cá; o tema admin do WHMCS sobrepõe a largura. */
  width:100%!important;box-sizing:border-box;height:auto;min-height:38px;
  font:inherit;font-weight:normal;line-height:1.35;
  color:var(--bcp-ink);background-color:var(--bcp-surface);
  border:1px solid var(--bcp-line);border-radius:var(--bcp-r-sm);
  padding:8px 12px;
}
.bcp-shell .bcp-fg-c textarea{min-height:78px;line-height:1.5}
/* Chevron SVG inline no <select>: background-image próprio, sem shorthand
   que zera; vale também no addon. */
.bcp-shell .bcp-fg-c select{
  -webkit-appearance:none;-moz-appearance:none;appearance:none;
  padding-right:38px;
  background-image:url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='12'%20height='12'%20viewBox='0%200%2024%2024'%20fill='none'%20stroke='%235b6472'%20stroke-width='2.5'%20stroke-linecap='round'%20stroke-linejoin='round'%3E%3Cpath%20d='m6%209%206%206%206-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 13px center;
  background-size:12px;
}
.bcp-shell .bcp-fg-c select::-ms-expand{display:none}
.bcp-shell .bcp-fg-c input:focus,
.bcp-shell .bcp-fg-c select:focus,
.bcp-shell .bcp-fg-c textarea:focus{
  outline:0;border-color:var(--bcp-accent);
  box-shadow:0 0 0 3px rgba(79,70,229,.16);
}
.bcp-fg-d{font-size:12px;color:var(--bcp-muted);
  line-height:1.45;margin-top:0;display:block}
.bcp-pick{
  /* sem scroll interno — exibe tudo na tela (mais prático). */
  border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-surface);
  padding:4px 0 10px;
}
/* cabeçalho de categoria (picker + diff) */
.bcp-pick-grp{
  font-size:11px;font-weight:bold;letter-spacing:.06em;
  text-transform:uppercase;color:var(--bcp-faint);
  padding:14px 14px 6px;
}
/* separador entre categorias (não na 1ª): pontilhado e RECUADO —
   começa/termina onde o conteúdo começa/termina, não encosta nas
   bordas do card (é separador, não divisória total). */
.bcp-pick .bcp-pick-grp:not(:first-child){
  margin-top:14px;padding-top:18px;position:relative;
}
.bcp-pick .bcp-pick-grp:not(:first-child)::before{
  content:"";position:absolute;left:14px;right:14px;top:0;
  border-top:1px dotted var(--bcp-line);
}
/* nota abaixo do picker não cola na borda do card de cima */
.bcp-pick + .bcp-fg-d{margin-top:10px}
/* 4 colunas (usa a largura inteira, menos rolagem); responsivo */
.bcp-pick-grid{
  display:grid;grid-template-columns:repeat(4,1fr);
  gap:2px 14px;padding:0 8px;
}
@media (max-width:1100px){
  .bcp-pick-grid{grid-template-columns:repeat(3,1fr)}
}
@media (max-width:820px){
  .bcp-pick-grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:560px){.bcp-pick-grid{grid-template-columns:1fr}}
/* .bcp-shell .bcp-chips-opt (0,2,0) VENCE o reset .bcp-shell label
   (0,1,1) — sem isso o label fica display:block e cola tudo. */
.bcp-shell .bcp-chips-opt{
  display:flex;align-items:center;gap:10px;
  padding:6px 8px;cursor:pointer;font-size:13px;font-weight:normal;
  border-radius:6px;min-width:0;
}
.bcp-shell .bcp-chips-opt:hover{background:var(--bcp-raised)}
.bcp-shell .bcp-chips-opt input[type=checkbox]{
  width:15px;height:15px;min-height:0;margin:0;flex:none;
  accent-color:var(--bcp-accent);cursor:pointer;
}
.bcp-chips-on{
  color:var(--bcp-ink);font-weight:normal;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
/* slug = badge Bootstrap (não reinventar): só alinhamento + mono */
.bcp-shell .bcp-chips-opt .bcp-slug{
  margin-left:auto;flex:none;font-family:var(--bcp-mono);
  font-weight:normal;text-transform:none;font-size:11.5px;
  padding:3px 8px;border-radius:6px;line-height:1.2;
  color:var(--bcp-accent-deep);
  background-color:rgba(79,70,229,.10);
  border:1px solid rgba(79,70,229,.22);
}
/* select de versão pinada por componente (à direita da linha) */
/* select de versão no mesmo padrão índigo dos badges */
.bcp-shell .bcp-chips-opt .bcp-cver{
  margin-left:auto;flex:none;width:auto;min-height:0;height:auto;
  font-size:11.5px;line-height:1.2;padding:3px 26px 3px 8px;
  border-radius:6px;color:var(--bcp-accent-deep);
  background-color:rgba(79,70,229,.10);
  border:1px solid rgba(79,70,229,.22);
  -webkit-appearance:none;-moz-appearance:none;appearance:none;
  cursor:pointer;
  /* chevron SVG inline (índigo) — sinaliza que é select */
  background-image:url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20width='12'%20height='12'%20viewBox='0%200%2024%2024'%20fill='none'%20stroke='%234338ca'%20stroke-width='2.5'%20stroke-linecap='round'%20stroke-linejoin='round'%3E%3Cpath%20d='m6%209%206%206%206-6'/%3E%3C/svg%3E");
  background-repeat:no-repeat;
  background-position:right 8px center;background-size:10px;
}
.bcp-shell .bcp-chips-opt .bcp-cver::-ms-expand{display:none}
.bcp-shell .bcp-chips-opt .bcp-cver:focus{
  outline:0;border-color:var(--bcp-accent);
  box-shadow:0 0 0 3px rgba(79,70,229,.16);
}
/* diff agrupado: badges Bootstrap com respiro, por categoria */
.bcp-difb{margin:0 0 16px}
.bcp-difb>strong{display:block;margin:0 0 4px;font-size:13px}
.bcp-difb .bcp-pick-grp{padding:8px 0 4px}
/* linha de situação: label + badge juntos, à esquerda (não esticar
   numa tabela full-width — o badge não fica solto no meio). */
.bcp-statrow{
  display:flex;align-items:center;gap:12px;
  padding:9px 0;border-bottom:1px solid var(--bcp-line);font-size:13px;
}
.bcp-statrow:last-of-type{border-bottom:0}
.bcp-statk{color:var(--bcp-muted);min-width:230px}
.bcp-badges{display:flex;flex-wrap:wrap;gap:6px}
/* badge no nosso padrão: índigo claro ao fundo, índigo escuro no
   texto (specificity 0,3,0 vence o .badge cinza do Bootstrap). */
.bcp-shell .bcp-badges .badge{
  font-weight:normal;text-transform:none;font-size:12px;
  padding:4px 9px;border-radius:7px;line-height:1.2;
  color:var(--bcp-accent-deep);
  background-color:rgba(79,70,229,.10);
  border:1px solid rgba(79,70,229,.22);
}
.bcp-chips-note,.bcp-chips-empty{
  padding:12px 14px;font-size:12px;color:var(--bcp-muted);
  font-weight:normal;
}
.bcp-chips-note{
  margin-top:16px;border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);background:var(--bcp-raised);
  color:var(--bcp-muted);line-height:1.5;
}
/* ícone Lucide inline no botão (currentColor) */
.bcp-shell .bcp-ico{
  display:inline-block;width:1em;height:1em;
  vertical-align:-.16em;margin-right:7px;
}

@keyframes bcp-in{to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){
  .bcp-row{animation:none;opacity:1;transform:none}
  .bcp-tip-pop{transition:none}
}
@media (max-width:760px){
  .bcp-component-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bcp-section-heading{align-items:flex-start;flex-direction:column;gap:8px}
}
@media (max-width:520px){
  .bcp-component-grid,.bcp-usage-grid,.bcp-activity{grid-template-columns:1fr}
  .bcp-card-bd{padding-left:14px;padding-right:14px}
  .bcp-hero{padding:16px 14px}
  .bcp-hero-actions{width:100%;margin-left:0}
}
CSS;
    }
}
