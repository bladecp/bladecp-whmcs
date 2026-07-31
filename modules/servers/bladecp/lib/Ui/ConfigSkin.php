<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Api\Catalog;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Personaliza a tela de configuração do produto (`configproducts.php`).
 *
 * Os hooks `AdminAreaHeadOutput` e `AdminAreaFooterOutput`, limitados pela
 * URL, aplicam CSS e JavaScript escopados aos campos de configuração. O
 * resultado inclui rótulos, ajuda contextual, descrição e cabeçalho da marca.
 *
 * Alguns campos são inseridos de forma assíncrona pelo WHMCS. Um
 * `MutationObserver` aguarda a disponibilidade deles e aplica a interface
 * uma única vez. Os controles originais preservam nome, valor e eventos para
 * que o salvamento nativo continue funcionando.
 */
final class ConfigSkin
{
    /** Casa só a tela de edição de produto. */
    public static function onConfigProducts(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return strpos($uri, 'configproducts.php') !== false
            && strpos($uri, 'action=edit') !== false;
    }

    /** Id do produto WHMCS na URL de edição (`?id=`). 0 se ausente. */
    public static function productId(): int
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('/[?&]id=(\d+)/', $uri, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * Addon de combos ativo? Checado pela EXISTÊNCIA da tabela
     * (acoplamento só por nome de tabela — o server module não
     * depende do código do addon). Fail-soft → false: sem addon, o
     * card não mostra o botão de bundles e o módulo segue funcionando
     * (combo é opcional).
     */
    public static function addonActive(): bool
    {
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return false;
            }
            return (bool) \WHMCS\Database\Capsule::schema()
                ->hasTable('mod_bladecp_combos');
        } catch (\Throwable) {
            return false;
        }
    }

    /** `<style>` — design system + estilos do markup remontado. */
    public static function headOutput(): string
    {
        if (!self::onConfigProducts()) {
            return '';
        }

        return '<style>' . Assets::css() . self::skinCss() . '</style>';
    }

    /** `<script>` — observer + rebuild (espera o AJAX dos ConfigOptions). */
    public static function footerOutput(): string
    {
        if (!self::onConfigProducts()) {
            return '';
        }
        $logo = json_encode(
            Brand::logo(22),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $jenc = static fn (string $s): string => json_encode(
            $s,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $icBun = $jenc(Icons::get('package'));
        $icOf = $jenc(Icons::get('refresh'));
        $icPr = $jenc(Icons::get('download'));
        // Catálogo de componentes no servidor para o seletor assistido. Fail-soft
        // total: sem Server record/key/rede → `[]` → o skin cai pro
        // input de texto simples, sem interromper a tela quando o catálogo
        // não estiver disponível.
        $catalog = [];
        try {
            $client = ServerConfig::client();
            if ($client !== null) {
                $catalog = Catalog::catalog($client) ?? [];
            }
        } catch (\Throwable) {
            $catalog = [];
        }
        $cat = json_encode(
            array_values($catalog),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        // Botão "Gerenciar bundles" ancorado no produto. Só
        // aparece se o addon de combos está ativo (tabela existe);
        // sem addon o módulo segue inteiro (combo é opcional).
        $pid = self::productId();
        $addonOn = $pid > 0 && self::addonActive();
        $bundles = $addonOn
            ? 'addonmodules.php?module=bladecp&pid=' . $pid
            : '';
        // Importação de preço opt-in, fornecida pelo addon; este hook
        // não possui endpoint próprio.
        $price = $addonOn
            ? 'addonmodules.php?module=bladecp&action=pricing&pid=' . $pid
            : '';
        // G — sync order form (Configurable Options) opt-in.
        $orderform = $addonOn
            ? 'addonmodules.php?module=bladecp&action=orderform&pid=' . $pid
            : '';
        $burl = json_encode(
            $bundles,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $purl = json_encode(
            $price,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $ofurl = json_encode(
            $orderform,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        // Campos NATIVOS do WHMCS (sem descrição própria) — keyed
        // pelo `name` do controle. `tip` = texto CURTO próprio do
        // tooltip; `desc` = texto completo da linha abaixo. São
        // textos distintos (NÃO o desc cortado).
        $help = json_encode([
            'servertype' => [
                'tip' => 'Módulo de provisionamento deste produto.',
                'desc' => 'Vincula o produto ao módulo de '
                    . 'provisionamento BladeCP, que executa o ciclo de '
                    . 'vida (criar/suspender/terminar/SSO) via API. '
                    . 'Mantenha "BladeCP".',
            ],
            // 'servergroup' não é mais exibido (omitido do card).
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Tooltip CURTO próprio dos ConfigOptions (a descrição
        // completa vem do WHMCS/cell). Keyed pelo label da opção.
        $tips = json_encode([
            'BladeCP Plan' => 'Plano do seu catálogo de parceiro que '
                . 'este produto provisiona.',
            'BladeCP Components' => 'Componentes que este produto '
                . 'provisiona.',
            'BladeCP Product Type' => 'Define qual produto BladeCP é '
                . 'provisionado.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Entrada pro addon SEMPRE visível (não só com addon ativo):
        // ativo → painel do addon ancorado no produto; inativo → lista
        // de Addon Modules pra ATIVAR. Sem isto, com o addon desligado
        // nada na tela indica que ele existe — o hoster fica sem rumo
        // (combos/import de preço/sync do order form vivem lá).
        $aActive = self::addonActive();
        $addonUrl = $aActive
            ? 'addonmodules.php?module=bladecp'
                . ($pid > 0 ? '&pid=' . $pid : '')
            : 'configaddonmods.php';
        $aurl = json_encode(
            $addonUrl,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        $albl = $jenc(
            $aActive ? 'Abrir addon BladeCP' : 'Ativar addon BladeCP'
        );
        $atit = $jenc(
            $aActive
                ? 'Gerencie combos de componentes, importe preço do '
                    . 'plano e sincronize o order form.'
                : 'O addon BladeCP habilita combos, import de preço e '
                    . 'sync do order form. Clique para ativá-lo na '
                    . 'lista de Addon Modules.'
        );
        $aon = $aActive ? 'true' : 'false';
        $icAddon = $jenc(
            Icons::get($aActive ? 'external-link' : 'plug')
        );

        return <<<HTML
<script>(function(){
  var LOGO=$logo,CAT=$cat,BURL=$burl,PURL=$purl,OFURL=$ofurl,
      ICB=$icBun,ICO=$icOf,ICP=$icPr,HELP=$help,TIPS=$tips,
      AURL=$aurl,ALBL=$albl,ATIT=$atit,AON=$aon,ICA=$icAddon,done=false;
  function build(){
    if(done||window.__bcpCfgSkinned)return;
    try{
      var st=document.querySelector('select[name="servertype"]')
        ||document.querySelector('#inputServerType');
      if(!st)return;
      var form=st.closest('form')||document;
      var pane=st.closest('div[role="tabpanel"]')
        ||st.closest('.tab-pane')||st.closest('[id^="tab"]')||form;
      // ConfigOptions do módulo (injetados via AJAX). Sem eles ainda
      // → espera (não monta card só com Module Name/Server Group).
      if(!form.querySelector('[name^="packageconfigoption"]'))return;

      var seen=[],ctrls=[];
      [].slice.call(form.querySelectorAll(
        // servergroup é COLETADO (pra esconder o campo nativo — senão
        // ele vaza fora do card) mas NÃO renderizado: move pra um
        // holder oculto no loop abaixo. BladeCP roteia pela bcp_* key
        // do Server record, nunca usa server group → inútil exibir.
        'select[name="servertype"],select[name="servergroup"],'
        +'[name^="packageconfigoption"]'
      )).forEach(function(c){
        var tag=(c.tagName||'').toLowerCase();
        if(tag!=='select'&&tag!=='textarea'&&tag!=='input')return;
        if(['hidden','submit','button','radio','checkbox']
          .indexOf(c.type)>-1)return;
        if(seen.indexOf(c)>-1)return;
        seen.push(c);ctrls.push(c);
      });
      if(!ctrls.length)return;
      done=true;window.__bcpCfgSkinned=true;

      var grid=document.createElement('div');
      grid.className='bcp-grid2';
      var blocks=[];
      ctrls.forEach(function(ctrl){
        var row=ctrl.closest('tr')||ctrl.closest('.fieldarea')
          ||ctrl.parentNode;
        var cell=ctrl.closest('td')||row;
        var blk=ctrl.closest('table');
        if(blk&&blocks.indexOf(blk)<0)blocks.push(blk);
        // Server Group: inútil pro BladeCP (roteia pela bcp_* key do
        // Server record). Coletado só pra TIRAR o campo nativo do
        // fluxo (senão ele "vaza" embaixo do card) — move pra holder
        // OCULTO dentro do grid (segue no form → WHMCS envia o
        // default None), some a row original, e NÃO renderiza no card.
        if((ctrl.name||ctrl.getAttribute&&ctrl.getAttribute('name'))
          ==='servergroup'){
          var hgrp=document.createElement('div');
          hgrp.style.display='none';
          hgrp.appendChild(ctrl);
          grid.appendChild(hgrp);
          if(row&&row.style)row.style.display='none';
          return;
        }
        var lbl='';
        if(ctrl.id){
          var lf=form.querySelector('label[for="'+ctrl.id+'"]');
          if(lf&&lf.textContent.trim())lbl=lf.textContent.trim();
        }
        if(!lbl){
          var pc=cell&&cell.previousElementSibling;
          if(pc&&!pc.contains(ctrl)){
            var pt=(pc.textContent||'').replace(/\s+/g,' ').trim();
            if(pt)lbl=pt;
          }
        }
        if(!lbl&&row&&row.querySelectorAll){
          var tds=[].slice.call(row.querySelectorAll('td'));
          for(var i=0;i<tds.length;i++){
            if(!tds[i].contains(ctrl)){
              var t=(tds[i].textContent||'')
                .replace(/\s+/g,' ').trim();
              if(t){lbl=t;break;}
            }
          }
        }
        lbl=(lbl||'').replace(/[:：]\s*$/,'').trim();
        var desc='';
        if(cell){
          var cl=cell.cloneNode(true);
          [].slice.call(cl.querySelectorAll(
            'select,input,textarea,label,script,style,button'
          )).forEach(function(n){n.parentNode&&
            n.parentNode.removeChild(n);});
          desc=(cl.textContent||'').replace(/\s+/g,' ').trim();
        }
        if(desc&&lbl&&desc===lbl)desc='';
        // Campos NATIVOS do WHMCS (Module Name/Server Group) não têm
        // descrição → injeta a nossa (vira o ? tooltip + a linha de
        // ajuda abaixo, igual aos ConfigOptions). Chave = name do
        // controle (robusto a idioma do admin).
        // tipTxt = tooltip CURTO próprio (texto distinto, NÃO o desc
        // cortado). Nativos: HELP[name]={tip,desc}. ConfigOptions:
        // desc vem do WHMCS, tip vem de TIPS[label]. Sem tip próprio
        // → o tooltip usa o desc completo (nunca corta/reticência).
        var tipTxt='';
        if(!desc){
          var cn=(ctrl.getAttribute&&ctrl.getAttribute('name'))
            ||ctrl.name||'';
          if(HELP[cn]){desc=HELP[cn].desc;tipTxt=HELP[cn].tip;}
        }
        if(!tipTxt){
          var lk=(lbl||'').replace(/\s+/g,' ').trim();
          if(TIPS[lk])tipTxt=TIPS[lk];
        }
        var fg=document.createElement('div');
        fg.className='bcp-fg';
        var l=document.createElement('label');
        l.className='bcp-fg-l';
        l.appendChild(document.createTextNode(lbl||''));
        if(desc){
          var tip=document.createElement('span');
          tip.className='bcp-tip';
          var q=document.createElement('b');
          q.setAttribute('tabindex','0');
          q.setAttribute('role','img');
          q.setAttribute('aria-label','Ajuda');
          q.textContent='?';
          var pop=document.createElement('span');
          pop.className='bcp-tip-pop';
          pop.setAttribute('role','tooltip');
          pop.textContent=tipTxt!==''?tipTxt:desc;
          tip.appendChild(q);tip.appendChild(pop);
          l.appendChild(document.createTextNode(' '));
          l.appendChild(tip);
        }
        var c=document.createElement('div');
        c.className='bcp-fg-c';
        c.appendChild(ctrl);
        fg.appendChild(l);fg.appendChild(c);
        if(desc){
          var d=document.createElement('small');
          d.className='bcp-fg-d';
          d.textContent=desc;
          fg.appendChild(d);
        }
        // O ConfigOption "BladeCP Components" (input texto cru,
        // slugs CSV) vira multi-select com chips alimentado pelo
        // catálogo. Valor continua CSV no MESMO input (escondido) →
        // WHMCS salva nativo, ParamsMapper não muda. Fail-soft: sem
        // catálogo, fica o input texto normal.
        if(CAT.length&&/^\s*BladeCP Components\s*$/i.test(lbl||'')
          &&(ctrl.tagName||'').toLowerCase()==='input'){
          try{
            bcpChips(c,ctrl,CAT);
            fg.className='bcp-fg bcp-fg-wide';
            if(d)d.style.display='none';
          }catch(e){/* fica o input cru */}
        }
        grid.appendChild(fg);
        if(row&&row.style)row.style.display='none';
      });

      // some os containers originais que ficaram sem campo (bordas
      // "fantasma" do WHMCS) — não toca em quem ainda tem controle
      // (ex.: bloco dos radios de auto-setup permanece).
      var hasCtl='select,input:not([type=hidden]):not([type=submit])'
        +':not([type=button]),textarea';
      blocks.forEach(function(b){
        try{
          if(b.querySelector(hasCtl))return;
          b.style.display='none';
          var p=b.parentElement;
          if(p&&p!==pane&&p!==form&&!p.querySelector(hasCtl)){
            p.style.display='none';
          }
        }catch(e){}
      });

      var shell=document.createElement('div');
      shell.className='bcp-shell bcp-cfg';
      var card=document.createElement('div');
      card.className='bcp-card';
      var hd=document.createElement('div');
      hd.className='bcp-card-hd';
      hd.innerHTML='<div class="bcp-card-hd-t">'
        +'<h3>Integração BladeCP</h3>'
        +'<p>Plano, location e componentes que este produto '
        +'provisiona.</p></div>';
      var hdr=document.createElement('div');hdr.className='bcp-hd-r';
      var ab=document.createElement('a');
      ab.className='btn '+(AON?'btn-default':'btn-primary')
        +' bcp-hd-addon';
      ab.href=AURL;ab.title=ATIT;ab.innerHTML=ICA;
      ab.appendChild(document.createTextNode(ALBL));
      hdr.appendChild(ab);
      hdr.insertAdjacentHTML('beforeend',LOGO);
      hd.appendChild(hdr);
      var bd=document.createElement('div');
      bd.className='bcp-card-bd';
      bd.appendChild(grid);
      // Entradas ancoradas no produto, disponíveis somente com o addon ativo.
      if(BURL||PURL||OFURL){
        var act=document.createElement('div');
        act.className='bcp-cfg-actions';
        if(BURL){
          var bb=document.createElement('a');
          bb.className='btn btn-default';bb.href=BURL;
          bb.innerHTML=ICB+'Gerenciar bundles de componentes';
          act.appendChild(bb);
        }
        if(OFURL){
          var ob=document.createElement('a');
          ob.className='btn btn-default';ob.href=OFURL;
          ob.innerHTML=ICO+'Sincronizar componentes';
          act.appendChild(ob);
        }
        if(PURL){
          var pb=document.createElement('a');
          pb.className='btn btn-default';pb.href=PURL;
          pb.innerHTML=ICP+'Importar preço do plano';
          act.appendChild(pb);
        }
        bd.appendChild(act);
      }
      card.appendChild(hd);card.appendChild(bd);
      shell.appendChild(card);
      pane.insertBefore(shell,pane.firstChild);

      [].slice.call(document.querySelectorAll('a,button,span'))
        .forEach(function(n){
          if(n.children.length)return;
          var t=(n.textContent||'').trim();
          if(/advanced mode|modo avan|simple mode|modo simpl/i
            .test(t)){n.style.display='none';}
        });
    }catch(e){/* fail-soft: tela do WHMCS segue intacta */}
  }
  // Multi-select com chips sobre o input CSV original (escondido,
  // mas vivo: name/valor preservados → WHMCS salva igual). Buscável,
  // agrupado por categoria, acessível (teclado/aria), idempotente.
  function bcpChips(box,input,cat){
    if(input.__bcpChips)return;input.__bcpChips=1;
    input.type='hidden';
    var byslug={};cat.forEach(function(it){byslug[it.slug]=it;});
    var groups={},order=[];
    cat.forEach(function(it){
      if(!groups[it.category]){groups[it.category]=[];order.push(it.category);}
      groups[it.category].push(it);
    });
    var sel={};
    (input.value||'').split(',').forEach(function(v){
      var t=v.trim();if(t)sel[t]=1;
    });
    var wrap=document.createElement('div');wrap.className='bcp-chips';
    var ctl=document.createElement('div');ctl.className='bcp-chips-ctl';
    ctl.tabIndex=0;ctl.setAttribute('role','button');
    ctl.setAttribute('aria-haspopup','listbox');
    ctl.setAttribute('aria-expanded','false');
    var pop=document.createElement('div');pop.className='bcp-chips-pop';
    var srch=document.createElement('input');srch.className='bcp-chips-srch';
    srch.type='text';srch.placeholder='Buscar componente…';
    srch.setAttribute('aria-label','Buscar componente');
    var list=document.createElement('div');list.className='bcp-chips-list';
    list.setAttribute('role','listbox');
    var note=document.createElement('div');note.className='bcp-chips-note';
    note.textContent='Catálogo global da BladeCP. A allowlist por '
      +'produto é aplicada no provisionamento.';
    function sync(){
      input.value=Object.keys(sel).join(',');
      input.dispatchEvent(new Event('input',{bubbles:true}));
      input.dispatchEvent(new Event('change',{bubbles:true}));
    }
    function renderCtl(){
      ctl.innerHTML='';
      var keys=Object.keys(sel);
      if(!keys.length){
        var ph=document.createElement('span');ph.className='bcp-chips-ph';
        ph.textContent='Nenhum componente — clique para escolher';
        ctl.appendChild(ph);
      }else{
        keys.forEach(function(k){
          var chip=document.createElement('span');chip.className='bcp-chip';
          chip.appendChild(document.createTextNode(
            byslug[k]?byslug[k].name:k));
          var x=document.createElement('b');x.textContent='×';
          x.setAttribute('role','button');
          x.setAttribute('aria-label','Remover '+k);
          x.addEventListener('click',function(e){
            e.stopPropagation();delete sel[k];
            sync();renderCtl();renderList();
          });
          chip.appendChild(x);ctl.appendChild(chip);
        });
      }
      var car=document.createElement('i');car.className='bcp-chips-car';
      ctl.appendChild(car);
    }
    function renderList(){
      var q=(srch.value||'').toLowerCase();
      list.innerHTML='';
      order.forEach(function(g){
        var items=groups[g].filter(function(it){
          return !q||it.name.toLowerCase().indexOf(q)>-1
            ||it.slug.toLowerCase().indexOf(q)>-1;
        });
        if(!items.length)return;
        var h=document.createElement('div');
        h.className='bcp-chips-grp';h.textContent=g;
        list.appendChild(h);
        items.forEach(function(it){
          var r=document.createElement('label');r.className='bcp-chips-opt';
          var cb=document.createElement('input');cb.type='checkbox';
          cb.checked=!!sel[it.slug];
          cb.addEventListener('change',function(){
            if(cb.checked)sel[it.slug]=1;else delete sel[it.slug];
            sync();renderCtl();
          });
          var nm=document.createElement('span');
          nm.className='bcp-chips-on';nm.textContent=it.name;
          var sg=document.createElement('code');sg.textContent=it.slug;
          r.appendChild(cb);r.appendChild(nm);r.appendChild(sg);
          list.appendChild(r);
        });
      });
      if(!list.children.length){
        var e=document.createElement('div');e.className='bcp-chips-empty';
        e.textContent='Nenhum componente encontrado.';
        list.appendChild(e);
      }
    }
    function open(){
      wrap.classList.add('is-open');
      ctl.setAttribute('aria-expanded','true');
      srch.focus();
    }
    function close(){
      wrap.classList.remove('is-open');
      ctl.setAttribute('aria-expanded','false');
    }
    ctl.addEventListener('click',function(){
      wrap.classList.contains('is-open')?close():open();
    });
    ctl.addEventListener('keydown',function(e){
      if(e.key==='Enter'||e.key===' '){
        e.preventDefault();
        wrap.classList.contains('is-open')?close():open();
      }else if(e.key==='Escape'){close();}
    });
    srch.addEventListener('input',renderList);
    srch.addEventListener('keydown',function(e){
      if(e.key==='Escape'){close();ctl.focus();}
    });
    document.addEventListener('click',function(e){
      if(!wrap.contains(e.target))close();
    });
    pop.appendChild(srch);pop.appendChild(list);pop.appendChild(note);
    wrap.appendChild(ctl);wrap.appendChild(pop);
    box.appendChild(wrap);
    renderCtl();renderList();
  }
  build();
  if(!done){
    try{
      var mo=new MutationObserver(function(){
        build();if(done&&mo){mo.disconnect();}
      });
      mo.observe(document.documentElement,
        {childList:true,subtree:true});
    }catch(e){}
    var n=0,iv=setInterval(function(){
      build();if(done||++n>40){clearInterval(iv);}
    },300);
  }
})();</script>
HTML;
    }

    /**
     * Estilos do NOSSO markup remontado (originais do WHMCS ficam
     * escondidos). Escopado `.bcp-*`; reusa `.bcp-tip` do Assets.
     * Header: título à esquerda, logo à direita (no MESMO header).
     */
    private static function skinCss(): string
    {
        return <<<'CSS'
.bcp-cfg .bcp-card-hd{justify-content:space-between;gap:16px}
.bcp-cfg .bcp-card-hd::before{display:none}
.bcp-cfg .bcp-card-hd-t{min-width:0}
.bcp-cfg .bcp-card-hd .bcp-hd-r{
  margin-left:auto;flex:none;display:flex;align-items:center;gap:16px;
}
.bcp-cfg .bcp-card-hd .bcp-hd-r .bcp-logo{margin-left:0;flex:none}
.bcp-cfg .bcp-hd-addon{white-space:nowrap}
@media (max-width:680px){
  .bcp-cfg .bcp-card-hd .bcp-hd-r{flex-wrap:wrap;gap:10px}
}
.bcp-cfg .bcp-card-bd{padding:20px 22px 22px}
.bcp-grid2{
  display:grid;grid-template-columns:repeat(3,1fr);
  column-gap:24px;row-gap:18px;
}
@media (max-width:1100px){.bcp-grid2{grid-template-columns:1fr 1fr}}
@media (max-width:680px){.bcp-grid2{grid-template-columns:1fr}}
.bcp-fg{display:flex;flex-direction:column;gap:6px;min-width:0}
.bcp-shell .bcp-fg-l{
  display:flex;align-items:center;gap:7px;
  font-size:13.5px;font-weight:bold;color:var(--bcp-ink);
  margin-bottom:0;
}
.bcp-fg-c{display:block}
/* input/select/textarea + chevron SVG inline + focus = FONTE ÚNICA
   no Assets (.bcp-shell .bcp-fg-c …). Não duplicar aqui: a duplicata
   brigava por specificity e sumia o chevron. */
.bcp-fg-d{font-size:12px;color:var(--bcp-muted);line-height:1.45}

/* Multi-select com chips (escopado; sobrepõe a regra genérica
   .bcp-fg-c input pros controles internos do widget). */
.bcp-fg-wide{grid-column:1/-1}
.bcp-chips{position:relative}
.bcp-fg-c .bcp-chips input{
  width:auto!important;min-height:0!important;height:auto!important;
}
.bcp-chips-ctl{
  display:flex;flex-wrap:wrap;align-items:center;gap:6px;
  min-height:38px;padding:6px 34px 6px 10px;cursor:pointer;
  background:var(--bcp-surface);border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);position:relative;
  transition:border-color .12s,box-shadow .12s;
}
.bcp-chips.is-open .bcp-chips-ctl,
.bcp-chips-ctl:focus-visible{
  outline:0;border-color:var(--bcp-accent);
  box-shadow:0 0 0 3px rgba(79,70,229,.16);
}
.bcp-chips-ph{color:var(--bcp-faint);font-size:13px}
.bcp-chip{
  display:inline-flex;align-items:center;gap:6px;
  padding:3px 4px 3px 9px;font-size:12px;font-weight:normal;
  color:var(--bcp-accent-deep);background:rgba(79,70,229,.09);
  border:1px solid rgba(79,70,229,.26);border-radius:7px;
}
.bcp-chip b{
  display:inline-flex;align-items:center;justify-content:center;
  width:15px;height:15px;border-radius:50%;cursor:pointer;
  font-size:12px;font-weight:bold;font-style:normal;
  color:var(--bcp-accent-deep);background:rgba(79,70,229,.16);
}
.bcp-chip b:hover{background:var(--bcp-accent);color:#04201f}
.bcp-chips-car{
  position:absolute;right:14px;top:50%;width:8px;height:8px;
  border:solid var(--bcp-faint);border-width:0 2px 2px 0;
  transform:translateY(-70%) rotate(45deg);pointer-events:none;
}
.bcp-chips.is-open .bcp-chips-car{transform:translateY(-30%) rotate(-135deg)}
.bcp-chips-pop{
  position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:40;
  background:var(--bcp-surface);border:1px solid var(--bcp-line);
  border-radius:var(--bcp-r-sm);box-shadow:var(--bcp-sh);
  display:none;overflow:hidden;
}
.bcp-chips.is-open .bcp-chips-pop{display:block}
.bcp-chips-srch{
  display:block;width:100%!important;border:0!important;
  border-bottom:1px solid var(--bcp-line)!important;
  border-radius:0!important;padding:10px 12px!important;
  font:inherit;color:var(--bcp-ink);background:var(--bcp-raised);
}
.bcp-chips-srch:focus{outline:0;box-shadow:none}
.bcp-chips-list{max-height:300px;overflow:auto;padding:4px 0}
.bcp-chips-grp{
  font-size:11px;font-weight:bold;letter-spacing:.08em;
  text-transform:uppercase;color:var(--bcp-faint);
  padding:9px 12px 4px;
}
.bcp-chips-opt{
  display:flex;align-items:center;gap:9px;
  padding:7px 12px;cursor:pointer;font-size:13px;
}
.bcp-chips-opt:hover{background:var(--bcp-raised)}
.bcp-chips-opt input{
  accent-color:var(--bcp-accent);cursor:pointer;flex:none;
}
.bcp-chips-on{color:var(--bcp-ink);font-weight:normal}
.bcp-chips-opt code{
  margin-left:auto;font-family:var(--bcp-mono);font-size:11.5px;
  color:var(--bcp-faint);
}
.bcp-chips-empty,.bcp-chips-note{
  padding:12px;font-size:12px;color:var(--bcp-muted);
}
.bcp-chips-note{
  border-top:1px solid var(--bcp-line);background:var(--bcp-raised);
  color:var(--bcp-faint);
}
.bcp-cfg-actions{
  display:flex;gap:9px;flex-wrap:wrap;
  margin-top:20px;padding-top:16px;
  border-top:1px solid var(--bcp-line);
}
CSS;
    }
}
