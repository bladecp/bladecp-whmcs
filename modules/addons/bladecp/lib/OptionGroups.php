<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Sincroniza as opções configuráveis precificadas do catálogo BladeCP
 * com as Configurable Options do WHMCS.
 *
 * DIFERENÇA CRÍTICA vs `ConfigurableOptions` (componentes):
 *  - `ConfigurableOptions::ensurePricing` fabrica preço ZERO de
 *    propósito (componentes são grátis) — NÃO é tocado aqui.
 *  - Opções configuráveis SÃO precificadas: `ensureOptionPricing`
 *    grava o preço da plataforma (por moeda × ciclo, UNIDADE, via
 *    `PriceMap`) — SOBRESCREVE no re-sync (a plataforma é a fonte do
 *    preço da opção; é o sentido de "sync precificado").
 *
 * Mapeamento, sem materialização por produto:
 *  - 1 `OptionGroup` da API → 1 `tblproductconfiggroups` (NOSSO).
 *  - 1 `Option`            → 1 `tblproductconfigoptions`.
 *  - 1 `OptionValue`       → 1 `tblproductconfigoptionssub`.
 *  - cada `OptionValueCurrency` (por ciclo) → `tblpricing` configoptions.
 *
 * Associação por plano: os
 * `$groups` passados a `apply()` são os grupos DO PLANO mapeado no
 * produto (resolvidos pelo chamador via `OptionCatalog::groupsForPlan`),
 * não mais o catálogo do produto. O `linkProduct` (gid↔pid no WHMCS)
 * é a representação WHMCS-side do vínculo plano↔grupo: como o produto
 * WHMCS mapeia 1:1 ao plano (ConfigOption "BladeCP Plan"), ligar o
 * grupo ao produto = ligar o grupo ao plano. A mecânica de sync não
 * muda — só a FONTE dos grupos (filtrada por plano pelo chamador).
 *
 * POSSE via `OptionGroupsMap` (group_uuid → gid) + marcador na
 * `description` (auto-cura se a posse for perdida). Idempotente,
 * reversível (`remove` desativa com tombstones), fail-soft (sem Capsule →
 * no-op/false).
 */
final class OptionGroups
{
    /** Marcador de posse na description do grupo WHMCS (auto-cura). */
    public const MARKER = 'BCP_OPT_GROUP:';

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    /**
     * Sincroniza os grupos recebidos (cria/atualiza estrutura + preço)
     * e liga ao produto os grupos em `$linkGroupUuids`. Idempotente, só
     * mexe nos NOSSOS grupos. true = ok.
     *
     * `$groups` contém os grupos do plano mapeado no produto.
     * (chamador resolve por `OptionCatalog::groupsForPlan`); o vínculo
     * ao produto WHMCS é a representação do vínculo plano↔grupo.
     *
     * @param array<int,array<string,mixed>> $groups  OptionCatalog::parseGroups
     * @param string[] $linkGroupUuids  uuids a vincular a $productId
     */
    public static function apply(
        array $groups,
        array $linkGroupUuids = [],
        ?int $productId = null
    ): bool {
        try {
            $db = self::db();
            if ($db === null) {
                return false;
            }
            $link = array_flip(array_map('strval', $linkGroupUuids));
            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }
                $uuid = trim((string) ($group['uuid'] ?? ''));
                if ($uuid === '') {
                    continue;
                }
                $gid = self::ensureGroup($group);
                if ($gid === null) {
                    continue;
                }
                self::reconcileOptions($gid, $group);
                if (
                    $productId !== null && $productId > 0
                    && isset($link[$uuid])
                ) {
                    self::linkProduct($gid, $productId);
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Grupo está ligado ao produto? Fail-soft false. */
    public static function isLinked(int $gid, int $pid): bool
    {
        try {
            $db = self::db();
            if ($db === null || $gid <= 0 || $pid <= 0) {
                return false;
            }
            return $db::table('tblproductconfiglinks')
                ->where('gid', $gid)->where('pid', $pid)->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Moedas do WHMCS sem preço sincronizado em TODAS as nossas
     * sub-options (mesma lógica de `ConfigurableOptions`, escopada aos
     * NOSSOS gids). Vazio = tudo coberto / sem grupos. Fail-soft `[]`.
     *
     * @return string[]
     */
    public static function currenciesMissingPricing(): array
    {
        try {
            $db = self::db();
            $gids = OptionGroupsMap::ownedGids();
            if ($db === null || $gids === []) {
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
                    ->whereIn('tblproductconfigoptions.gid', $gids)
                    ->get(['tblproductconfigoptionssub.id']) as $s
            ) {
                $subIds[] = (int) $s->id;
            }
            $need = count($subIds);
            if ($need === 0) {
                return [];
            }
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

    /**
     * Desativa todos os nossos grupos sem apagar opções, valores, pricing ou
     * o mapa reverso. Assim pedidos e serviços históricos permanecem
     * interpretáveis, inclusive após uma remoção explícita do catálogo.
     */
    public static function remove(): bool
    {
        try {
            $db = self::db();
            if ($db === null) {
                return false;
            }
            foreach (OptionGroupsMap::ownedGids() as $gid) {
                if ($gid <= 0) {
                    continue;
                }
                foreach (
                    $db::table('tblproductconfigoptions')
                        ->where('gid', $gid)->get() as $o
                ) {
                    self::tombstoneOption((int) $o->id);
                }
                $db::table('tblproductconfiglinks')
                    ->where('gid', $gid)->delete();
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Implementação interna ──

    /**
     * Id do grupo WHMCS deste OptionGroup (posse → marcador → cria).
     *
     * @param array<string,mixed> $group
     */
    private static function ensureGroup(array $group): ?int
    {
        $db = self::db();
        if ($db === null) {
            return null;
        }
        $uuid = trim((string) ($group['uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $name = self::groupName($group);
        $desc = self::MARKER . $uuid
            . ' — opções configuráveis sincronizadas (gerenciado pelo '
            . 'módulo BladeCP; não editar à mão).';

        // 1. posse explícita.
        $gid = OptionGroupsMap::gidForGroup($uuid);
        if ($gid !== null && self::groupExists($gid)) {
            $db::table('tblproductconfiggroups')->where('id', $gid)
                ->update(['name' => $name, 'description' => $desc]);
            return $gid;
        }
        // 2. auto-cura: adota o grupo pelo marcador (posse perdida).
        $row = $db::table('tblproductconfiggroups')
            ->where('description', 'like', '%' . self::MARKER . $uuid . '%')
            ->first();
        if ($row !== null) {
            $gid = (int) $row->id;
            $db::table('tblproductconfiggroups')->where('id', $gid)
                ->update(['name' => $name, 'description' => $desc]);
            OptionGroupsMap::rememberGroup($uuid, $gid, $name);
            return $gid;
        }
        // 3. cria.
        $gid = (int) $db::table('tblproductconfiggroups')->insertGetId([
            'name' => $name,
            'description' => $desc,
        ]);
        OptionGroupsMap::rememberGroup($uuid, $gid, $name);
        return $gid;
    }

    private static function groupExists(int $gid): bool
    {
        $db = self::db();
        if ($db === null || $gid <= 0) {
            return false;
        }
        return $db::table('tblproductconfiggroups')
            ->where('id', $gid)->first() !== null;
    }

    /** @param array<string,mixed> $group */
    private static function groupName(array $group): string
    {
        $n = trim((string) ($group['name'] ?? ''));
        return $n !== '' ? $n : 'BladeCP Option Group';
    }

    /**
     * Reconcilia options (config options) + values (sub-options) +
 * pricing do grupo. Itens que saem do catálogo viram tombstones ocultos,
 * preservando ids, pedidos e preços históricos.
     *
     * @param array<string,mixed> $group
     */
    private static function reconcileOptions(int $gid, array $group): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $uuid = (string) ($group['uuid'] ?? '');
        $options = is_array($group['options'] ?? null) ? $group['options'] : [];

        // Config options atuais do grupo, por id e por nome. O nome é
        // somente fallback de compatibilidade para instalações pré-UUID.
        $existingById = [];
        $existingByName = [];
        foreach (
            $db::table('tblproductconfigoptions')
                ->where('gid', $gid)->get() as $o
        ) {
            $id = (int) $o->id;
            $existingById[$id] = true;
            $existingByName[(string) $o->optionname] = $id;
        }

        $retainedConfigIds = [];
        $order = 0;
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $optName = trim((string) ($opt['name'] ?? ''));
            $optUuid = trim((string) ($opt['uuid'] ?? ''));
            if ($optName === '' || $optUuid === '') {
                continue;
            }
            $order++;
            $type = (int) ($opt['optionType'] ?? 1);
            $qtyMin = $type === 4 && isset($opt['minQty'])
                ? (int) $opt['minQty'] : 0;
            $qtyMax = $type === 4 && isset($opt['maxQty'])
                ? (int) $opt['maxQty'] : 0;

            $configId = OptionGroupsMap::configIdForOption($uuid, $optUuid);
            if ($configId === null || !isset($existingById[$configId])) {
                $configId = $existingByName[$optName] ?? null;
            }

            if ($configId !== null) {
                $db::table('tblproductconfigoptions')
                    ->where('id', $configId)
                    ->update([
                        'optionname' => $optName,
                        'optiontype' => $type,
                        'order' => $order,
                        'qtyminimum' => $qtyMin,
                        'qtymaximum' => $qtyMax,
                        'hidden' => 0,
                    ]);
            } else {
                $configId = (int) $db::table('tblproductconfigoptions')
                    ->insertGetId([
                        'gid' => $gid,
                        'optionname' => $optName,
                        'optiontype' => $type,
                        'qtyminimum' => $qtyMin,
                        'qtymaximum' => $qtyMax,
                        'order' => $order,
                        'hidden' => 0,
                    ]);
            }
            $retainedConfigIds[$configId] = true;
            self::reconcileValues($gid, $configId, $uuid, $opt);
        }

        // Nunca apagar option/sub/pricing durante o sync. Um valor que saiu
        // da API pode estar presente em pedidos e serviços históricos.
        foreach (array_keys($existingById) as $configId) {
            if (!isset($retainedConfigIds[$configId])) {
                self::tombstoneOption($configId);
            }
        }
    }

    /**
     * Reconcilia as sub-options (values) + pricing de um config option,
     * e grava o mapa reverso.
     *
     * @param array<string,mixed> $opt
     */
    private static function reconcileValues(
        int $gid,
        int $configId,
        string $groupUuid,
        array $opt
    ): void {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $optName = (string) ($opt['name'] ?? '');
        $optUuid = (string) ($opt['uuid'] ?? '');
        $optType = (int) ($opt['optionType'] ?? 1);
        $resourceKey = isset($opt['resourceKey']) && is_string($opt['resourceKey'])
            ? $opt['resourceKey'] : null;
        $values = is_array($opt['values'] ?? null) ? $opt['values'] : [];

        $existingById = [];
        $existingByName = [];
        foreach (
            $db::table('tblproductconfigoptionssub')
                ->where('configid', $configId)->get() as $s
        ) {
            $id = (int) $s->id;
            $existingById[$id] = true;
            $existingByName[(string) $s->optionname] = $id;
        }

        $retainedSubIds = [];
        $sort = 0;
        foreach ($values as $val) {
            if (!is_array($val)) {
                continue;
            }
            $label = trim((string) ($val['label'] ?? ''));
            $valUuid = trim((string) ($val['uuid'] ?? ''));
            if ($label === '' || $valUuid === '') {
                continue;
            }
            $isDefault = !empty($val['isDefault']);
            $currencies = is_array($val['currencies'] ?? null)
                ? $val['currencies'] : [];

            $subId = OptionGroupsMap::subIdForValue(
                $groupUuid,
                $optUuid,
                $valUuid,
                $configId
            );
            if ($subId === null || !isset($existingById[$subId])) {
                $subId = $existingByName[$label] ?? null;
            }
            if ($subId !== null) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $subId)
                    ->update([
                        'optionname' => $label,
                        'sortorder' => $sort,
                        'hidden' => 0,
                    ]);
            } else {
                $subId = (int) $db::table('tblproductconfigoptionssub')
                    ->insertGetId([
                        'configid' => $configId,
                        'optionname' => $label,
                        'sortorder' => $sort,
                        'hidden' => 0,
                    ]);
            }
            self::ensureOptionPricing($subId, $currencies, $isDefault);
            OptionGroupsMap::rememberValue(
                $subId,
                $configId,
                $groupUuid,
                $optUuid,
                $optName,
                $optType,
                $label,
                $valUuid,
                $isDefault,
                $resourceKey
            );
            $retainedSubIds[$subId] = true;
            $sort++;
        }

        // Tombstone, sem apagar pricing ou o mapa: pedidos antigos continuam
        // resolvíveis mesmo quando o catálogo deixa de oferecer o value.
        foreach (array_keys($existingById) as $subId) {
            if (!isset($retainedSubIds[$subId])) {
                $db::table('tblproductconfigoptionssub')
                    ->where('id', $subId)
                    ->update(['hidden' => 1]);
            }
        }
    }

    /**
     * Grava uma linha `tblpricing configoptions` por moeda
     * do WHMCS, com ciclos (`PriceMap::suggested`) + setup
     * (`PriceMap::suggestedSetup`), UNIDADE. SOBRESCREVE no re-sync (a
     * plataforma é a fonte do preço).
     *
     * A regra de cada linha (default vs não-default; ciclo precificado
     * vs ausente) vive em `optionPricingRow`. Um ciclo ou moeda sem preço de
     * um value NÃO-default vira `-1` (indisponível), não `0` (grátis).
     *
     * @param array<int,array<string,mixed>> $currencies
     */
    private static function ensureOptionPricing(
        int $subId,
        array $currencies,
        bool $isDefault
    ): void {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $byCode = [];
        foreach ($currencies as $c) {
            if (!is_array($c)) {
                continue;
            }
            $code = strtoupper(trim((string) ($c['currency'] ?? '')));
            if ($code !== '') {
                $byCode[$code] = $c;
            }
        }
        $cycleCols = array_values(PriceMap::MAP);
        $setupCols = array_values(PriceMap::SETUP_MAP);

        foreach ($db::table('tblcurrencies')->get() as $cur) {
            $cid = (int) $cur->id;
            $code = strtoupper((string) ($cur->code ?? ''));
            $api = $byCode[$code] ?? null;

            $data = self::optionPricingRow(
                $api,
                $isDefault,
                $cycleCols,
                $setupCols
            );

            $row = $db::table('tblpricing')
                ->where('type', 'configoptions')
                ->where('relid', $subId)
                ->where('currency', $cid)
                ->first();
            if ($row !== null) {
                $db::table('tblpricing')
                    ->where('id', (int) $row->id)->update($data);
            } else {
                $db::table('tblpricing')->insert(array_merge([
                    'type' => 'configoptions',
                    'currency' => $cid,
                    'relid' => $subId,
                ], $data));
            }
        }
    }

    /**
     * Linha de preço (`tblpricing
     * configoptions`) de UM value numa UMA moeda do WHMCS.
     *
     * Uma opção sem preço fica indisponível, e não gratuita:
     *  - `isDefault` ("Nenhum (incluído)") → tudo `0` (mantém o base do
     *    plano, não cobra). É o ÚNICO caso que recebe 0.
     *  - NÃO-default sem preço da plataforma nesta MOEDA (`$api` null) →
     *    ciclos `-1` (indisponível). Em WHMCS, `0` numa configurable
     *    option = incluído/grátis: gravar 0 num ciclo não precificado
     *    vazaria receita (o upgrade sairia de graça).
     *  - NÃO-default precificado, mas um CICLO sem preço
     *    (`PriceMap::amount()` → null) → esse ciclo `-1` (indisponível),
     *    não `0`. `Pricing::apply` também usa `-1` para um ciclo não
     *    definido.
     *
     * Setup fee: `0` quando ausente (não existe "setup indisponível";
     * o `-1` é semântica de CICLO no WHMCS, não de taxa de setup).
     *
     * @param array<string,mixed>|null $api  moeda da OptionValue (ou null)
     * @param string[] $cycleCols  colunas de ciclo (PriceMap::MAP)
     * @param string[] $setupCols  colunas de setup (PriceMap::SETUP_MAP)
     * @return array<string,float>
     */
    public static function optionPricingRow(
        ?array $api,
        bool $isDefault,
        array $cycleCols,
        array $setupCols
    ): array {
        $data = [];
        if ($isDefault) {
            // default = "incluído": mantém o base do plano, não cobra.
            foreach ($cycleCols as $col) {
                $data[$col] = 0.0;
            }
            foreach ($setupCols as $col) {
                $data[$col] = 0.0;
            }
            return $data;
        }
        if ($api === null) {
            // não-default sem preço da plataforma nesta moeda →
            // INDISPONÍVEL (-1), nunca grátis. Setup 0.
            foreach ($cycleCols as $col) {
                $data[$col] = -1.0;
            }
            foreach ($setupCols as $col) {
                $data[$col] = 0.0;
            }
            return $data;
        }
        // não-default precificado: ciclo sem preço (amount()==null) →
        // -1 (indisponível), NÃO 0. `?? 0.0` era o vazamento de receita.
        $cycles = PriceMap::suggested($api)['cycles'];
        $setup = PriceMap::suggestedSetup($api);
        foreach ($cycleCols as $col) {
            $data[$col] = $cycles[$col] ?? -1.0;
        }
        foreach ($setupCols as $col) {
            $data[$col] = $setup[$col] ?? 0.0;
        }
        return $data;
    }

    private static function linkProduct(int $gid, int $pid): void
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

    private static function tombstoneOption(int $configId): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        $db::table('tblproductconfigoptionssub')
            ->where('configid', $configId)->update(['hidden' => 1]);
        $db::table('tblproductconfigoptions')
            ->where('id', $configId)->update(['hidden' => 1]);
    }
}
