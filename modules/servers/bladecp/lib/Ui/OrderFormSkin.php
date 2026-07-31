<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Ui;

use WHMCS\Module\Server\Bladecp\Api\Catalog;
use WHMCS\Module\Server\Bladecp\Helpers\ComboResolver;
use WHMCS\Module\Server\Bladecp\Helpers\ComponentRef;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Aprimoramento progressivo do formulário do cliente.
 *
 * O tema do WHMCS renderiza as Configurable Options 100% nativo — NÃO
 * substituímos markup. Só injetamos um `<script>`/`<style>` via
 * `ClientAreaFooterOutput` que, ao escolher um combo (radio, 1ª
 * opção), preenche e TRAVA os selects de categoria com os
 * componentes/versões do combo (visual "definido pelo combo"); "—
 * Nenhum —" reabilita; Location segue livre. Um combo ativo
 * (`_CreateAccount`): combo ativo SOBREPÕE a escolha manual na
 * provisão → travar = honesto (não mente o que será provisionado).
 *
 * SEGURANÇA (não pode quebrar o Configure Product de outros
 * produtos/clientes):
 *  - allowlist ESTRITA: o JS só toca `configoption[<id>]` cujos ids
 *    são EXCLUSIVAMENTE os do NOSSO grupo (lidos do DB — globais e
 *    únicos no WHMCS). Nunca seletor genérico `configoption[*]`.
 *  - self-gating: se os elementos do nosso grupo não estão na página
 *    → no-op imediato (outro produto → ids ausentes → zero efeito).
 *  - fail-soft absoluto: qualquer ausência/erro → string vazia.
 *
 * Acoplamento só por marcadores ESTÁVEIS já contratados (nome do
 * grupo "BladeCP", opções "Combo"/"Location", sentinelas), igual ao
 * ComboResolver, o server module NÃO depende do código do addon.
 * `buildModel()` não depende da disponibilidade do catálogo ou dos combos.
 */
final class OrderFormSkin
{
    private static bool $hookRegistered = false;

    /** Marcadores estáveis usados pelas Configurable Options. */
    private const GROUP_NAME = 'BladeCP';
    private const COMBO_OPTION = 'Combo';
    private const LOCATION_OPTION = 'Location';
    private const COMBO_NONE = '— Nenhum —';
    private const COMPONENT_NONE = '— não incluir —';

    /** Só no cart/checkout (onde o order form vive). */
    public static function onCart(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        return str_contains($uri, 'cart.php')
            || str_contains($uri, '/cart/')
            || str_contains($uri, 'a=confproduct')
            || str_contains($uri, 'a=configureproduct');
    }

    /**
     * Registra a melhoria por qualquer entrypoint BladeCP descoberto pelo
     * WHMCS. O addon cria e administra os combos, então também funciona como
     * fallback quando o server module foi instalado depois da ativação do
     * produto e seu hooks.php ainda não entrou no cache de módulos do WHMCS.
     */
    public static function registerHook(): void
    {
        if (self::$hookRegistered || !function_exists('add_hook')) {
            return;
        }
        self::$hookRegistered = true;

        add_hook('ClientAreaFooterOutput', 1, static function ($vars): string {
            try {
                return self::clientFooterOutput();
            } catch (\Throwable) {
                return '';
            }
        });
    }

    /** `ClientAreaFooterOutput` → `<style>`+`<script>` ou ''. */
    public static function clientFooterOutput(): string
    {
        try {
            if (
                !self::onCart()
                || !class_exists(\WHMCS\Database\Capsule::class)
            ) {
                return '';
            }
            $model = self::buildModel();
            if ($model === null) {
                return '';
            }
            return self::render($model);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Modelo allowlistado pro JS (ou null se não há o que fazer).
     * Lê os ids REAIS do nosso grupo no DB + catálogo + combos.
     *
     * @return array{
     *   comboId:int, comboNoneSubId:int,
     *   comboSubs:array<int,string>,
     *   cats:array<int,array{id:int,noneSub:int}>,
     *   map:array<string,array<int,int>>
     * }|null
     */
    public static function buildModel(): ?array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        $db = \WHMCS\Database\Capsule::class;
        $group = $db::table('tblproductconfiggroups')
            ->where('name', self::GROUP_NAME)
            ->first();
        if ($group === null) {
            return null;
        }
        $gid = (int) $group->id;

        $comboId = 0;
        $locId = 0;
        $catByName = [];   // categoria (nome da opção) => optId
        $catNoneSub = [];  // optId => subId "— não incluir —"
        $comboNoneSubId = 0;
        $comboSubs = [];   // subId => nome do combo (inclui o "Nenhum")
        // subId por (optId,label) p/ resolver alvo do combo.
        $subByOpt = [];    // optId => [label => subId]

        foreach (
            $db::table('tblproductconfigoptions')
                ->where('gid', $gid)->get() as $o
        ) {
            $oid = (int) $o->id;
            $oname = (string) $o->optionname;
            $subs = [];
            foreach (
                $db::table('tblproductconfigoptionssub')
                    ->where('configid', $oid)->get() as $s
            ) {
                $subs[(string) $s->optionname] = (int) $s->id;
            }
            $subByOpt[$oid] = $subs;

            if ($oname === self::COMBO_OPTION) {
                $comboId = $oid;
                foreach ($subs as $label => $sid) {
                    $comboSubs[$sid] = $label;
                    if ($label === self::COMBO_NONE) {
                        $comboNoneSubId = $sid;
                    }
                }
            } elseif ($oname === self::LOCATION_OPTION) {
                $locId = $oid;
            } else {
                $catByName[$oname] = $oid;
                $catNoneSub[$oid] = $subs[self::COMPONENT_NONE] ?? 0;
            }
        }

        // Sem combo ou sem categoria → nada pra dirigir.
        if (
            $comboId === 0 || $comboNoneSubId === 0
            || $catByName === []
        ) {
            return null;
        }

        $catalog = self::catalog();
        $combos = ComboResolver::allCombos();
        if ($catalog === [] || $combos === []) {
            return null;
        }

        // slug => [name, category(nome da opção), verId => label]
        $cinfo = [];
        foreach ($catalog as $c) {
            $slug = trim((string) ($c['slug'] ?? ''));
            $name = trim((string) ($c['name'] ?? ''));
            if ($slug === '' || $name === '') {
                continue;
            }
            $cat = trim((string) ($c['category'] ?? '')) ?: 'Outros';
            $vers = [];
            foreach ((array) ($c['versions'] ?? []) as $v) {
                $vid = (int) ($v['id'] ?? 0);
                if ($vid > 0) {
                    $vers[$vid] = trim((string) ($v['label'] ?? ''));
                }
            }
            $cinfo[$slug] = [
                'name' => $name,
                'cat' => $cat,
                'vers' => $vers,
            ];
        }

        // map[comboNome][catOptId] = subId (alvo no select). Categoria
        // não coberta → subId do "— não incluir —" daquela categoria.
        $map = [];
        foreach ($combos as $cname => $tokens) {
            $row = [];
            foreach ($catByName as $optId) {
                $row[(int) $optId] = (int) ($catNoneSub[$optId] ?? 0);
            }
            foreach ($tokens as $tok) {
                $ref = ComponentRef::parse((string) $tok);
                $slug = $ref['slug'];
                if ($slug === '' || !isset($cinfo[$slug])) {
                    continue;
                }
                $ci = $cinfo[$slug];
                $optId = $catByName[$ci['cat']] ?? 0;
                if ($optId === 0) {
                    continue;
                }
                $vlbl = null;
                if ($ref['version'] !== null) {
                    $vlbl = $ci['vers'][$ref['version']] ?? null;
                }
                $label = ComponentRef::optionLabel($ci['name'], $vlbl);
                $sid = $subByOpt[$optId][$label] ?? 0;
                if ($sid > 0) {
                    $row[(int) $optId] = (int) $sid;
                }
            }
            $map[(string) $cname] = $row;
        }

        $cats = [];
        foreach ($catByName as $optId) {
            $cats[] = [
                'id' => (int) $optId,
                'noneSub' => (int) ($catNoneSub[$optId] ?? 0),
            ];
        }

        return [
            'comboId' => $comboId,
            'comboNoneSubId' => $comboNoneSubId,
            'comboSubs' => $comboSubs,
            'cats' => $cats,
            'map' => $map,
        ];
    }

    /** Catálogo rico (fail-soft `[]`, igual ConfigSkin). */
    private static function catalog(): array
    {
        try {
            if (!class_exists(ServerConfig::class)) {
                return [];
            }
            $client = ServerConfig::client();
            if ($client === null) {
                return [];
            }
            return Catalog::catalog($client) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $m */
    private static function render(array $m): string
    {
        $json = json_encode(
            $m,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
        );
        $css = '<style>'
            . '.bcp-of-locked{opacity:.62;pointer-events:none;'
            . 'cursor:not-allowed;background-image:none}'
            . '.bcp-of-tag{display:inline-block;margin-left:8px;'
            . 'font-size:11px;font-weight:bold;line-height:1.6;'
            . 'padding:0 8px;border-radius:6px;color:#4338ca;'
            . 'background:rgba(79,70,229,.10);vertical-align:middle}'
            . '</style>';

        // JS vanilla, idempotente, self-gating, allowlist estrita.
        $js = <<<'JS'
<script>(function(){
  if(window.__bcpOfSkin)return;
  var D=__BCP_OF__;
  if(!D||!D.comboId||!D.cats||!D.cats.length)return;
  function q(id){return document.querySelectorAll(
    '[name="configoption['+id+']"]');}
  function tagText(){
    var lang=(document.documentElement.lang||'en').toLowerCase();
    if(lang.indexOf('pt')===0)return 'definido pelo combo';
    if(lang.indexOf('es')===0)return 'definido por el combo';
    return 'defined by combo';}
  var combo=q(D.comboId);
  if(!combo.length)return;            // nosso grupo não está aqui → no-op
  window.__bcpOfSkin=1;
  var applyTimer=null,summaryTimer=null;
  function summarySelect(){
    for(var i=0;i<D.cats.length;i++){var els=q(D.cats[i].id);
      for(var j=0;j<els.length;j++)if(els[j].tagName==='SELECT')return els[j];}
    return null;}
  function syncSummary(el){
    if(summaryTimer!==null)clearTimeout(summaryTimer);
    summaryTimer=setTimeout(function(){
      summaryTimer=null;
      el=el||summarySelect();
      // Um único change, depois de todas as opções receberem seus valores,
      // faz o WHMCS serializar o estado final num único recálculo. Formulários
      // sem jQuery usam o recalculador global legado como fallback.
      if(typeof window.jQuery==='function'&&el){
        try{window.jQuery(el).trigger('change');}catch(e){}
      }else if(typeof window.recalctotals==='function'){
        try{window.recalctotals();}catch(e){}
      }
    },0);
  }
  function curSub(){
    for(var i=0;i<combo.length;i++){var e=combo[i];
      if(e.tagName==='SELECT')return e.value;
      if(e.checked)return e.value;}
    return null;}
  function setOpt(id,sid){
    var changed=null,els=q(id);for(var i=0;i<els.length;i++){var e=els[i];
      if(e.tagName==='SELECT'){if(String(e.value)!==String(sid)){
        e.value=String(sid);if(changed===null)changed=e;}}
      else{var on=String(e.value)===String(sid);
        if(e.checked!==on){e.checked=on;if(changed===null)changed=e;}}}
    return changed;}
  function lock(id,on,tgt){
    var els=q(id);for(var i=0;i<els.length;i++){var e=els[i];
      if(on){e.classList.add('bcp-of-locked');
        e.setAttribute('aria-disabled','true');
        e.setAttribute('tabindex','-1');
        if(!e.__bcpGuard){e.__bcpGuard=1;
          e.addEventListener('change',function(){
            if(this.__bcpTgt!=null&&String(this.value)!==
              String(this.__bcpTgt)){this.value=String(this.__bcpTgt);}});}
        e.__bcpTgt=tgt;
        var lbl=e.closest('.form-group,.row,div')&&
          e.closest('.form-group,.row,div').querySelector('label');
        if(lbl&&!lbl.querySelector('.bcp-of-tag')){
          var t=document.createElement('span');
          t.className='bcp-of-tag';t.textContent=tagText();
          lbl.appendChild(t);}
      }else{e.classList.remove('bcp-of-locked');
        e.removeAttribute('aria-disabled');e.removeAttribute('tabindex');
        e.__bcpTgt=null;
        var l2=e.closest('.form-group,.row,div')&&
          e.closest('.form-group,.row,div').querySelector(
          '.bcp-of-tag');
        if(l2)l2.parentNode.removeChild(l2);}}}
  function apply(notifySummary){
    var sid=curSub();
    var name=(sid!=null)?D.comboSubs[sid]:null;
    var active=(sid!=null)&&String(sid)!==String(D.comboNoneSubId)
      &&name&&D.map[name];
    var changed=null;
    for(var i=0;i<D.cats.length;i++){var c=D.cats[i];
      if(active){
        var tgt=(D.map[name][c.id]!=null)?D.map[name][c.id]:c.noneSub;
        var current=setOpt(c.id,tgt);if(changed===null&&current)changed=current;
        lock(c.id,true,tgt);
      }else{lock(c.id,false);}}
    if(notifySummary&&changed)syncSummary(changed);}
  function queueApply(){
    if(applyTimer!==null)clearTimeout(applyTimer);
    applyTimer=setTimeout(function(){applyTimer=null;apply(true);},0);}
  // O tema six usa iCheck (jQuery): o radio real fica opacity:0 e o
  // clique pode disparar ifChanged e change. A delegação com debounce cobre
  // ambos sem duplicar o lote; o listener nativo é fallback sem jQuery.
  if(window.jQuery){try{window.jQuery(document).on(
    'ifChanged change',
    '[name="configoption['+D.comboId+']"]',
    queueApply);}catch(e){}}
  else{for(var i=0;i<combo.length;i++)
    combo[i].addEventListener('change',queueApply);}
  // Prepara o estado final sincronicamente. O próprio WHMCS executa a
  // atualização inicial do resumo depois que o documento fica pronto.
  apply(false);
  // Garante a aplicação inicial se o tema inicializar radios/iCheck depois
  // da injeção do footer. Só recalcula se essa etapa alterar algum valor.
  setTimeout(function(){apply(true);},60);
})();</script>
JS;
        $js = str_replace('__BCP_OF__', $json, $js);
        return $css . $js;
    }
}
