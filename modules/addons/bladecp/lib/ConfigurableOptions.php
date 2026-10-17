<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ComponentAvailability;
use WHMCS\Module\Server\Bladecp\Helpers\ComponentRef;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Sincroniza as opções configuráveis BladeCP no WHMCS.
 */
final class ConfigurableOptions
{
    /** Nome do grupo de opções BladeCP. */
    public const GROUP_NAME = 'BladeCP';
    public const COMBO_OPTION = 'Combo';
    public const COMBO_NONE = '— Nenhum —';
    /** Location escolhível pelo cliente (um produto, várias localizações). */
    public const LOCATION_OPTION = 'Location';
    /** Primeira subopção do componente: não incluir (opt-in). */
    public const COMPONENT_NONE = '— não incluir —';

    public const TYPE_YESNO = 3;
    public const TYPE_DROPDOWN = 1;
    /** WHMCS optiontype 2 = radio; Combo vem primeiro e Location por último. */
    public const TYPE_RADIO = 2;

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    // ── Helpers de dados ──

    /**
     * Catálogo e combos formam a configuração desejada do grupo. Determinística.
     *
     * 1 dropdown POR CATEGORIA — sub-options =
     * "— não incluir —" + `<Componente> v<versão>` (cada
     * componente×versão da categoria). Uma opção por categoria é
     * estrutural, pois o WHMCS força seleção única no dropdown; a versão
     * fica definida no mesmo passo. Inclui também o dropdown "Combo"
     * (caminho principal). Location é sincronizada separadamente por
     * plano. Aplicações independentes não são expressáveis aqui e são
     * validadas no servidor.
     *
     * @param array<int,array{slug:string,name:string,category:string,versions?:array}> $catalog
     * @param array<int,array{id:int,name:string,components:string[]}> $combos
     * @param array<string,string> $locations  id => label (Loaders::locations)
     * @return array{group:string,options:array<int,array{name:string,type:int,slug:?string,subs:string[]}>}
     */
    public static function desiredSpec(
        array $catalog,
        array $combos,
        array $locations = []
    ): array {
        $options = [];
        // agrupa por categoria preservando 1ª aparição.
        $byCat = [];
        $catOrder = [];
        $seenSlug = [];
        foreach ($catalog as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            $slug = trim((string) ($c['slug'] ?? ''));
            if ($name === '' || $slug === '' || isset($seenSlug[$slug])) {
                continue;
            }
            $seenSlug[$slug] = true;
            $cat = trim((string) ($c['category'] ?? '')) ?: 'Outros';
            if (!isset($byCat[$cat])) {
                $byCat[$cat] = [];
                $catOrder[] = $cat;
            }
            $byCat[$cat][] = $c;
        }
        // ordem de categoria do owner (mesma do combo/diff).
        usort($catOrder, static fn ($a, $b): int =>
            \WHMCS\Module\Server\Bladecp\Helpers\SelectionRules::categoryRank($a)
                <=> \WHMCS\Module\Server\Bladecp\Helpers\SelectionRules::categoryRank($b)
            ?: strcasecmp((string) $a, (string) $b));
        // categorias = dropdowns no MEIO (ordem do owner já no $catOrder)
        $catOpts = [];
        foreach ($catOrder as $cat) {
            $subs = [self::COMPONENT_NONE];
            foreach ($byCat[$cat] as $c) {
                $cname = trim((string) ($c['name'] ?? ''));
                $versions = is_array($c['versions'] ?? null)
                    ? $c['versions'] : [];
                if ($versions === []) {
                    $subs[] = ComponentRef::optionLabel($cname, null);
                    continue;
                }
                foreach ($versions as $v) {
                    $subs[] = ComponentRef::optionLabel(
                        $cname,
                        trim((string) ($v['label'] ?? ''))
                    );
                }
            }
            if (count($subs) > 1) {
                $catOpts[] = [
                    'name' => $cat,
                    'type' => self::TYPE_DROPDOWN,
                    'slug' => null,
                    'subs' => array_values(array_unique($subs)),
                ];
            }
        }
        // Combo é a primeira opção, em rádio, para que o cliente veja os
        // presets primeiro; uma escolha dirige os selects.
        // Combos REAIS primeiro, "— Nenhum —" por ÚLTIMO: o WHMCS
        // pré-marca o 1º radio → o 1º combo já vem default (sai um
        // setup sensato pronto); "— Nenhum —" é o opt-out explícito
        // lá embaixo. Sentinela continua a MESMA string (server-side
        // O processamento no servidor usa o valor, e não a posição.
        // A combo with a component (or pinned version) that left the catalog
        // cannot be provisioned, so it leaves the order form until edited.
        $comboOpt = null;
        $comboSubs = [];
        $available = ComponentAvailability::index($catalog);
        foreach ($combos as $cb) {
            $n = trim((string) ($cb['name'] ?? ''));
            $tokens = is_array($cb['components'] ?? null) ? $cb['components'] : [];
            if ($available !== [] && ComponentAvailability::unavailableTokens($tokens, $available) !== []) {
                continue;
            }
            if ($n !== '' && !in_array($n, $comboSubs, true)) {
                $comboSubs[] = $n;
            }
        }
        $comboSubs[] = self::COMBO_NONE;
        if (count($comboSubs) > 1) {
            $comboOpt = [
                'name' => self::COMBO_OPTION,
                'type' => self::TYPE_RADIO,
                'slug' => null,
                'subs' => $comboSubs,
            ];
        }
        // Ordem final: Combo → categorias. Location tem grupo por plano.
        if ($comboOpt !== null) {
            $options[] = $comboOpt;
        }
        foreach ($catOpts as $o) {
            $options[] = $o;
        }
        return ['group' => self::GROUP_NAME, 'options' => $options];
    }

    /**
     * Diff entre desejado e atual (nomes de opção). Puro.
     *
     * @param string[] $desiredNames
     * @param string[] $currentNames
     * @return array{add:string[],remove:string[],keep:string[]}
     */
    public static function diffSpec(array $desiredNames, array $currentNames): array
    {
        $add = array_values(array_diff($desiredNames, $currentNames));
        $remove = array_values(array_diff($currentNames, $desiredNames));
        $keep = array_values(array_intersect($desiredNames, $currentNames));
        return ['add' => $add, 'remove' => $remove, 'keep' => $keep];
    }

    /**
     * Combos that reference a component or pinned version the catalog no
     * longer offers: comboName => unavailable tokens. Pure.
     *
     * @param array<int,array{id:int,name:string,components:string[]}> $combos
     * @param array<int,array<string,mixed>> $catalog
     * @return array<string,string[]>
     */
    public static function unavailableCombos(array $combos, array $catalog): array
    {
        $available = ComponentAvailability::index($catalog);
        if ($available === []) {
            return [];
        }
        $out = [];
        foreach ($combos as $cb) {
            $name = trim((string) ($cb['name'] ?? ''));
            $tokens = is_array($cb['components'] ?? null) ? $cb['components'] : [];
            $bad = ComponentAvailability::unavailableTokens($tokens, $available);
            if ($name !== '' && $bad !== []) {
                $out[$name] = $bad;
            }
        }
        return $out;
    }

    /**
     * What a sync changes for the customer, choice by choice: the labels
     * each option will offer against the ones it offers today (visible
     * only; already hidden choices are not "removed" again). Pure.
     *
     * @param array{options:array<int,array{name:string,subs:string[]}>} $spec
     * @param array<string,string[]> $currentVisible optionName => visible labels
     * @return array{add:array<string,string[]>,remove:array<string,string[]>,keep:array<string,string[]>}
     */
    public static function choiceDiff(array $spec, array $currentVisible): array
    {
        $sentinels = [self::COMPONENT_NONE, self::COMBO_NONE];
        $desired = [];
        foreach ($spec['options'] as $o) {
            foreach ($o['subs'] as $sub) {
                if (!in_array($sub, $sentinels, true)) {
                    $desired[$o['name']][] = (string) $sub;
                }
            }
        }
        $out = ['add' => [], 'remove' => [], 'keep' => []];
        foreach (array_unique(array_merge(array_keys($desired), array_keys($currentVisible))) as $name) {
            $want = $desired[$name] ?? [];
            $have = [];
            foreach ($currentVisible[$name] ?? [] as $label) {
                if (!in_array($label, $sentinels, true)) {
                    $have[] = (string) $label;
                }
            }
            foreach (['add' => array_diff($want, $have), 'remove' => array_diff($have, $want), 'keep' => array_intersect($want, $have)] as $k => $list) {
                if ($list !== []) {
                    $out[$k][(string) $name] = array_values(array_unique($list));
                }
            }
        }
        return $out;
    }

    /**
     * Visible choices of the BladeCP group today: optionName => labels.
     * Hidden options and hidden choices are left out. Fail-soft `[]`.
     *
     * @return array<string,string[]>
     */
    public static function currentVisibleChoices(): array
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return [];
            }
            $out = [];
            foreach ($db::table('tblproductconfigoptions')->where('gid', $gid)->get() as $o) {
                if ((int) ($o->hidden ?? 0) === 1) {
                    continue;
                }
                $labels = [];
                foreach ($db::table('tblproductconfigoptionssub')->where('configid', (int) $o->id)->get() as $sub) {
                    if ((int) ($sub->hidden ?? 0) !== 1) {
                        $labels[] = (string) $sub->optionname;
                    }
                }
                $out[(string) $o->optionname] = $labels;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    // ── DB (fail-soft, idempotente) ──

    /** Id do NOSSO grupo (cria se não existe). null fail-soft. */
    public static function groupId(bool $create = false): ?int
    {
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $g = $db::table('tblproductconfiggroups')
                ->where('name', self::GROUP_NAME)
                ->first();
            if ($g !== null) {
                return (int) $g->id;
            }
            if (!$create) {
                return null;
            }
            return (int) $db::table('tblproductconfiggroups')->insertGetId([
                'name' => self::GROUP_NAME,
                'description' => 'Componentes e combos BladeCP — '
                    . 'sincronizado do catálogo (gerenciado pelo módulo).',
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Nomes das opções atuais do NOSSO grupo. */
    public static function currentOptionNames(): array
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return [];
            }
            $out = [];
            foreach (
                $db::table('tblproductconfigoptions')
                    ->where('gid', $gid)->get() as $o
            ) {
                $out[] = (string) $o->optionname;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Reconcilia o grupo com a configuração desejada e, opcionalmente, liga ao
     * produto. Idempotente, só mexe no NOSSO grupo. true = ok.
     *
     * @param array<int,array{slug:string,name:string,category:string}> $catalog
     * @param array<int,array{id:int,name:string,components:string[]}> $combos
     */
    public static function apply(
        array $catalog,
        array $combos,
        ?int $productId = null,
        array $locations = []
    ): bool {
        try {
            $db = self::db();
            if ($db === null) {
                return false;
            }
            $gid = self::groupId(true);
            if ($gid === null) {
                return false;
            }
            $spec = self::desiredSpec($catalog, $combos, $locations);
            $desired = [];
            foreach ($spec['options'] as $o) {
                $desired[$o['name']] = $o;
            }

            $existing = [];
            foreach (
                $db::table('tblproductconfigoptions')
                    ->where('gid', $gid)->get() as $o
            ) {
                $existing[(string) $o->optionname] = (int) $o->id;
            }

            // O Location legado permanece no banco, oculto, para não quebrar
            // seleções históricas. Novos pedidos usam LocationOptions.
            foreach ($existing as $name => $oid) {
                if (!isset($desired[$name])) {
                    self::tombstoneOption($oid);
                }
            }
            // Cria/garante o desejado.
            $order = 0;
            foreach ($spec['options'] as $o) {
                $order++;
                if (isset($existing[$o['name']])) {
                    $oid = $existing[$o['name']];
                    $db::table('tblproductconfigoptions')
                        ->where('id', $oid)
                        ->update([
                            'optiontype' => $o['type'],
                            'order' => $order,
                            'hidden' => 0,
                        ]);
                } else {
                    $oid = (int) $db::table('tblproductconfigoptions')
                        ->insertGetId([
                            'gid' => $gid,
                            'optionname' => $o['name'],
                            'optiontype' => $o['type'],
                            'qtyminimum' => 0,
                            'qtymaximum' => 0,
                            'order' => $order,
                            'hidden' => 0,
                        ]);
                }
                self::reconcileSubs($oid, $o['subs']);
            }

            if ($productId !== null && $productId > 0) {
                self::linkProduct($gid, $productId);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Liga o grupo ao produto (idempotente). */
    public static function linkProduct(int $gid, int $pid): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $has = $db::table('tblproductconfiglinks')
                ->where('gid', $gid)->where('pid', $pid)->first();
            if ($has === null) {
                $db::table('tblproductconfiglinks')
                    ->insert(['gid' => $gid, 'pid' => $pid]);
            }
        } catch (\Throwable) {
        }
    }

    /** Desvincula o grupo BladeCP de um produto (modo Simples). Fail-soft. */
    public static function unlinkProduct(int $gid, int $pid): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $db::table('tblproductconfiglinks')
                ->where('gid', $gid)->where('pid', $pid)->delete();
        } catch (\Throwable) {
        }
    }

    /** Pids ligados ao grupo BladeCP → `[pid => true]`. Fail-soft `[]`. */
    public static function linkedProductIds(): array
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return [];
            }
            $out = [];
            foreach ($db::table('tblproductconfiglinks')->where('gid', $gid)->get() as $l) {
                $pid = (int) ($l->pid ?? 0);
                if ($pid > 0) {
                    $out[$pid] = true;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Sincronização estrutural automática pelo hook `DailyCronJob`, sem cron
     * adicional. Reaplica a configuração do grupo a cada produto já vinculado,
     * componente novo no catálogo aparece sozinho no order form.
     *
     * Idempotente + preço-preservador (`reconcileSubs`/`ensurePricing`
     * não tocam `tblpricing` existente) + reversível. NUNCA cria grupo
     * (`groupId(false)`) nem auto-vincula produto novo — o 1º sync
     * continua opt-in manual. ABORTA se o catálogo estiver
     * indisponível (rodar `apply` sem catálogo esvaziaria o grupo).
     * Fail-soft total: nunca estoura o cron do parceiro. Loga 1 linha
     * no Activity Log por execução (desatendido mas auditável).
     *
     * @return array{ran:bool,reason?:string,products:array<int,array{pid:int,add:int,remove:int,keep:int}>}
     */
    public static function syncAllLinked(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return ['ran' => false, 'reason' => 'no-db', 'products' => []];
            }
            $gid = self::groupId(false);
            if ($gid === null) {
                return [
                    'ran' => false,
                    'reason' => 'no-group',
                    'products' => [],
                ];
            }
            $catalog = self::serverCatalog();
            if ($catalog === []) {
                // SEM catálogo: abortar. `apply` aqui removeria todas
                // as sub-options "fora do catálogo" = esvaziaria o
                // grupo do parceiro. No-op é o seguro.
                self::cronLog('BladeCP auto-sync abortado: catálogo '
                    . 'indisponível (no-op, grupo preservado).');
                return [
                    'ran' => false,
                    'reason' => 'no-catalog',
                    'products' => [],
                ];
            }
            $combos = Combos::all();
            $locations = self::serverLocations();
            $spec = self::desiredSpec($catalog, $combos, $locations);
            $desiredNames = array_map(
                static fn ($o) => (string) $o['name'],
                $spec['options']
            );
            $products = [];
            foreach (
                $db::table('tblproductconfiglinks')
                    ->where('gid', $gid)->get() as $l
            ) {
                $pid = (int) $l->pid;
                if ($pid <= 0) {
                    continue;
                }
                $currentNames = [];
                foreach (
                    $db::table('tblproductconfigoptions')
                        ->where('gid', $gid)->get() as $o
                ) {
                    $currentNames[] = (string) $o->optionname;
                }
                $diff = self::diffSpec($desiredNames, $currentNames);
                self::apply($catalog, $combos, $pid, $locations);
                $products[] = [
                    'pid' => $pid,
                    'add' => count($diff['add']),
                    'remove' => count($diff['remove']),
                    'keep' => count($diff['keep']),
                ];
            }
            if ($products !== []) {
                $chg = 0;
                foreach ($products as $p) {
                    $chg += $p['add'] + $p['remove'];
                }
                self::cronLog(sprintf(
                    'BladeCP auto-sync estrutural: %d produto(s), '
                    . '%d mudança(s) de opção (preço preservado).',
                    count($products),
                    $chg
                ));
            }
            return ['ran' => true, 'products' => $products];
        } catch (\Throwable $e) {
            self::cronLog('BladeCP auto-sync erro fail-soft: '
                . $e->getMessage());
            return ['ran' => false, 'reason' => 'error', 'products' => []];
        }
    }

    /** Catálogo rico via server module (ponte autoload). Fail-soft []. */
    private static function serverCatalog(): array
    {
        try {
            $sc = 'WHMCS\\Module\\Server\\Bladecp\\Helpers\\ServerConfig';
            $ca = 'WHMCS\\Module\\Server\\Bladecp\\Api\\Catalog';
            if (!class_exists($sc) || !class_exists($ca)) {
                return [];
            }
            $client = $sc::client();
            if ($client === null) {
                return [];
            }
            return $ca::catalog($client) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Locations do partner via server module. Fail-soft []. */
    private static function serverLocations(): array
    {
        try {
            $ld = 'WHMCS\\Module\\Server\\Bladecp\\Config\\Loaders';
            if (!class_exists($ld)) {
                return [];
            }
            return $ld::locations();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Auditoria desatendida (substitui o confirm interativo). */
    private static function cronLog(string $msg): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($msg);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Moedas do WHMCS que NÃO têm preço sincronizado em TODAS as
     * sub-options do nosso grupo (`tblpricing type='configoptions'`).
     * O WHMCS só renderiza a Configurable Option numa moeda se houver
     * essa linha → moeda adicionada depois do sync deixa o order form
     * "sem opções" nela até re-sincronizar. Retorna os CODES faltando
     * (vazio = tudo coberto / sem grupo). Fail-soft `[]`.
     *
     * @return string[]
     */
    public static function currenciesMissingPricing(): array
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return [];
            }
            $subIds = [];
            foreach (
                $db::table('tblproductconfigoptionssub')
                    ->join(
                        'tblproductconfigoptions',
                        'tblproductconfigoptions.id',
                        '=',
                        'tblproductconfigoptionssub.configid'
                    )
                    ->where('tblproductconfigoptions.gid', $gid)
                    ->get(['tblproductconfigoptionssub.id']) as $s
            ) {
                $subIds[] = (int) $s->id;
            }
            $need = count($subIds);
            if ($need === 0) {
                return [];
            }
            // contagem de linhas configoptions por moeda (só nossos subs)
            $have = [];
            foreach (
                $db::table('tblpricing')
                    ->where('type', 'configoptions')
                    ->whereIn('relid', $subIds)
                    ->get(['currency']) as $p
            ) {
                $cid = (int) $p->currency;
                $have[$cid] = ($have[$cid] ?? 0) + 1;
            }
            $missing = [];
            foreach ($db::table('tblcurrencies')->get() as $c) {
                $cid = (int) $c->id;
                if (($have[$cid] ?? 0) < $need) {
                    $missing[] = strtoupper((string) $c->code);
                }
            }
            return $missing;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function isLinked(int $pid): bool
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return false;
            }
            return $db::table('tblproductconfiglinks')
                ->where('gid', $gid)->where('pid', $pid)->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Desativa a composição sem apagar pedidos, opções ou pricing. Os dados
     * permanecem tombstoned para que serviços e faturas antigas continuem
     * interpretáveis; um novo apply() reativa somente o catálogo atual.
     */
    public static function remove(): bool
    {
        try {
            $db = self::db();
            $gid = self::groupId(false);
            if ($db === null || $gid === null) {
                return false;
            }
            foreach (
                $db::table('tblproductconfigoptions')
                    ->where('gid', $gid)->get() as $o
            ) {
                self::tombstoneOption((int) $o->id);
            }
            $db::table('tblproductconfiglinks')->where('gid', $gid)->delete();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Implementação interna ──

    private static function tombstoneOption(int $oid): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $db::table('tblproductconfigoptionssub')
            ->where('configid', $oid)->update(['hidden' => 1]);
        $db::table('tblproductconfigoptions')
            ->where('id', $oid)->update(['hidden' => 1]);
    }

    /** @param string[] $subs */
    private static function reconcileSubs(int $oid, array $subs): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $existing = [];
        foreach (
            $db::table('tblproductconfigoptionssub')
                ->where('configid', $oid)->get() as $s
        ) {
            $existing[(string) $s->optionname] = (int) $s->id;
        }
        $want = [];
        $sort = 0;
        foreach ($subs as $sub) {
            $sub = (string) $sub;
            $want[$sub] = true;
            if (isset($existing[$sub])) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $existing[$sub])
                    ->update(['sortorder' => $sort, 'hidden' => 0]);
                self::ensurePricing($existing[$sub]);
            } else {
                $sid = (int) $db::table('tblproductconfigoptionssub')
                    ->insertGetId([
                        'configid' => $oid,
                        'optionname' => $sub,
                        'sortorder' => $sort,
                        'hidden' => 0,
                    ]);
                self::ensurePricing($sid);
            }
            $sort++;
        }
        foreach ($existing as $name => $sid) {
            if (!isset($want[$name])) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $sid)->update(['hidden' => 1]);
            }
        }
    }

    /** Garante 1 linha tblpricing (a 0) por moeda p/ a sub-option. */
    public static function ensurePricing(int $subId): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        foreach ($db::table('tblcurrencies')->get() as $cur) {
            $cid = (int) $cur->id;
            $has = $db::table('tblpricing')
                ->where('type', 'configoptions')
                ->where('relid', $subId)
                ->where('currency', $cid)
                ->first();
            if ($has !== null) {
                continue;
            }
            $db::table('tblpricing')->insert([
                'type' => 'configoptions',
                'currency' => $cid,
                'relid' => $subId,
                'msetupfee' => 0,
                'qsetupfee' => 0,
                'ssetupfee' => 0,
                'asetupfee' => 0,
                'bsetupfee' => 0,
                'tsetupfee' => 0,
                'monthly' => 0,
                'quarterly' => 0,
                'semiannually' => 0,
                'annually' => 0,
                'biennially' => 0,
                'triennially' => 0,
            ]);
        }
    }
}
