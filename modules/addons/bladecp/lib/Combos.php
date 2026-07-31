<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Camada de dados dos combos (Capsule). Combo = bundle nomeado de
 * slugs de componentes, reutilizável e atribuível a um produto WHMCS.
 *
 * Tabelas (prefixo `mod_` da convenção de addon WHMCS):
 *  - `mod_bladecp_combos`          id,name,slug,components(CSV),ts
 *  - `mod_bladecp_product_combos`  product_id(PK),combo_id,ts
 *
 * O server module (provisioning) LÊ `product_id → combo → slugs`
 * direto dessas tabelas via `Helpers\ComboResolver` (sem depender do
 * código deste addon — só dos nomes de tabela, documentados aqui).
 * Combo é OPCIONAL: addon não ativado → tabelas ausentes →
 * ComboResolver fail-soft `[]`, o módulo provisiona sem combo.
 *
 * Sem banco disponível, as operações retornam sem alterar dados.
 */
final class Combos
{
    public const T_COMBOS = 'mod_bladecp_combos';
    public const T_PRODUCT = 'mod_bladecp_product_combos';

    private static function schema(): mixed
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        return \WHMCS\Database\Capsule::schema();
    }

    private static function db(): mixed
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        return \WHMCS\Database\Capsule::class;
    }

    public static function installSchema(): void
    {
        $s = self::schema();
        if ($s === null) {
            return;
        }
        if (!$s->hasTable(self::T_COMBOS)) {
            $s->create(self::T_COMBOS, static function ($t): void {
                $t->increments('id');
                $t->string('name');
                $t->string('slug')->unique();
                $t->text('components');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
        if (!$s->hasTable(self::T_PRODUCT)) {
            $s->create(self::T_PRODUCT, static function ($t): void {
                $t->integer('product_id')->primary();
                $t->integer('combo_id');
                $t->timestamp('updated_at')->nullable();
            });
        }
        self::healAccess();
    }

    /**
     * Auto-cura do "Access Denied" recorrente: o WHMCS, ao
     * (re)ativar um addon, insere uma 2ª linha `tbladdonmodules`
     * `access` (vazia) em vez de atualizar — fica `[]` + `[1,2,3]`,
     * e o WHMCS lê a vazia → nega acesso até pro admin geral. Não é
     * alteração indevida de grupos de outros módulos.
     *
     * Em toda ativação: apaga TODAS as linhas `access` de `bladecp`
     * e grava UMA com todos os admin role groups. Idempotente,
     * fail-soft. O admin pode reduzir depois em Addon Modules; em
     * cada reativação volta a liberar (comportamento esperado).
     */
    public static function healAccess(): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $roleIds = [];
            foreach ($db::table('tbladminroles')->get() as $r) {
                $roleIds[] = (int) $r->id;
            }
            if ($roleIds === []) {
                $roleIds = [1];
            }
            $db::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', 'access')
                ->delete();
            $db::table('tbladdonmodules')->insert([
                'module' => 'bladecp',
                'setting' => 'access',
                'value' => implode(',', $roleIds),
            ]);
        } catch (\Throwable) {
        }
    }

    public static function uninstallSchema(): void
    {
        $s = self::schema();
        if ($s === null) {
            return;
        }
        $s->dropIfExists(self::T_PRODUCT);
        $s->dropIfExists(self::T_COMBOS);
    }

    /** Addon ativo = tabela existe. Fail-soft → false. */
    public static function isInstalled(): bool
    {
        try {
            $s = self::schema();
            return $s !== null && $s->hasTable(self::T_COMBOS);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array{id:int,name:string,slug:string,components:string[]}> */
    public static function all(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return [];
            }
            $rows = $db::table(self::T_COMBOS)->orderBy('name')->get();
            $out = [];
            foreach ($rows as $r) {
                $out[] = self::hydrate($r);
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{id:int,name:string,slug:string,components:string[]}|null */
    public static function find(int $id): ?array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $r = $db::table(self::T_COMBOS)->where('id', $id)->first();
            return $r === null ? null : self::hydrate($r);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Setting (tbladdonmodules) do modo de importação de planos. */
    public const SETTING_IMPORT_COMBO = 'import_combo';

    /**
     * Combo id do MODO SIMPLES de importação (o combo fixo que os
     * produtos importados usam, sem expor opções). `null` = modo
     * Composável (cliente escolhe). Valida que o combo ainda existe.
     */
    public static function importComboId(): ?int
    {
        $db = self::db();
        if ($db === null) {
            return null;
        }
        try {
            $r = $db::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', self::SETTING_IMPORT_COMBO)
                ->first();
            $id = $r !== null ? (int) ($r->value ?? 0) : 0;

            return ($id > 0 && self::find($id) !== null) ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Grava o modo de importação (`null`/0 = Composável). Fail-soft. */
    public static function setImportComboId(?int $comboId): void
    {
        $db = self::db();
        if ($db === null) {
            return;
        }
        try {
            $val = ($comboId !== null && $comboId > 0) ? (string) $comboId : '';
            $q = $db::table('tbladdonmodules')
                ->where('module', 'bladecp')
                ->where('setting', self::SETTING_IMPORT_COMBO);
            if ($q->exists()) {
                $q->update(['value' => $val]);
            } else {
                $db::table('tbladdonmodules')->insert([
                    'module' => 'bladecp',
                    'setting' => self::SETTING_IMPORT_COMBO,
                    'value' => $val,
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Cria/atualiza. Retorna `['ok'=>bool,'errors'=>string[],'id'=>?int]`.
     *
     * @param string[] $components
     */
    public static function save(
        ?int $id,
        string $name,
        array $components
    ): array {
        $name = trim($name);
        $slugs = self::cleanSlugs($components);
        $errors = [];
        if ($name === '') {
            $errors[] = 'Nome do combo é obrigatório.';
        }
        if ($slugs === []) {
            $errors[] = 'Selecione ao menos um componente.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id];
        }
        $db = self::db();
        if ($db === null) {
            return [
                'ok' => false,
                'errors' => ['Banco indisponível.'],
                'id' => $id,
            ];
        }
        try {
            $payload = [
                'name' => $name,
                'slug' => self::uniqueSlug(self::slugify($name), $id),
                'components' => implode(',', $slugs),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($id !== null && self::find($id) !== null) {
                $db::table(self::T_COMBOS)
                    ->where('id', $id)
                    ->update($payload);
            } else {
                $payload['created_at'] = date('Y-m-d H:i:s');
                $id = (int) $db::table(self::T_COMBOS)
                    ->insertGetId($payload);
            }
            return ['ok' => true, 'errors' => [], 'id' => $id];
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()], 'id' => $id];
        }
    }

    public static function delete(int $id): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $db::table(self::T_PRODUCT)->where('combo_id', $id)->delete();
            $db::table(self::T_COMBOS)->where('id', $id)->delete();
        } catch (\Throwable) {
        }
    }

    /** Atribui (ou limpa, `$comboId === null`) o combo de um produto. */
    public static function assignProduct(int $productId, ?int $comboId): void
    {
        try {
            $db = self::db();
            if ($db === null) {
                return;
            }
            $db::table(self::T_PRODUCT)
                ->where('product_id', $productId)
                ->delete();
            if ($comboId !== null) {
                $db::table(self::T_PRODUCT)->insert([
                    'product_id' => $productId,
                    'combo_id' => $comboId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    public static function comboIdForProduct(int $productId): ?int
    {
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $r = $db::table(self::T_PRODUCT)
                ->where('product_id', $productId)
                ->first();
            return $r === null ? null : (int) $r->combo_id;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Helpers de dados ──

    /** `"PHP Stack 8.2"` → `"php-stack-8-2"`. */
    public static function slugify(string $s): string
    {
        // translitera acentos Latin → ASCII ANTES do strip
        // ("Padrão" → "padrao", não "padr-o"). strtr com MAPA
        // (multibyte-safe). Determinístico, sem locale/iconv.
        $map = [];
        $set = [
            'a' => ['à','á','â','ã','ä','å','ā','À','Á','Â','Ã','Ä','Å','Ā'],
            'c' => ['ç','ć','č','Ç','Ć','Č'],
            'e' => ['è','é','ê','ë','ē','ė','ę','È','É','Ê','Ë','Ē','Ė','Ę'],
            'i' => ['ì','í','î','ï','ī','į','Ì','Í','Î','Ï','Ī','Į'],
            'n' => ['ñ','ń','Ñ','Ń'],
            'o' => ['ò','ó','ô','õ','ö','ø','ō','Ò','Ó','Ô','Õ','Ö','Ø','Ō'],
            'u' => ['ù','ú','û','ü','ū','Ù','Ú','Û','Ü','Ū'],
            'y' => ['ÿ','ý','Ÿ','Ý'],
            'z' => ['ž','Ž'],
        ];
        foreach ($set as $ascii => $chars) {
            foreach ($chars as $ch) {
                $map[$ch] = $ascii;
            }
        }
        $s = strtr($s, $map);
        // remove qualquer multibyte residual (ex.: emoji)
        $s = preg_replace('/[^\x00-\x7F]+/', '', $s) ?? '';
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }

    /**
     * Normaliza entrada de componentes: aceita array de slugs (dos
     * checkboxes) OU string CSV (fallback textarea offline). Trim +
     * dedupe + drop vazio, ordem preservada.
     *
     * @param string[]|string $input
     * @return string[]
     */
    public static function parseComponentsInput(array|string $input): array
    {
        $parts = is_array($input) ? $input : explode(',', $input);
        return self::cleanSlugs($parts);
    }

    /**
     * @param string[] $slugs
     * @return string[]
     */
    private static function cleanSlugs(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $s) {
            $s = trim((string) $s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private static function uniqueSlug(string $base, ?int $exceptId): string
    {
        $base = $base !== '' ? $base : 'combo';
        $db = self::db();
        if ($db === null) {
            return $base;
        }
        $slug = $base;
        $n = 2;
        while (true) {
            $q = $db::table(self::T_COMBOS)->where('slug', $slug);
            if ($exceptId !== null) {
                $q->where('id', '!=', $exceptId);
            }
            if ($q->first() === null) {
                return $slug;
            }
            $slug = $base . '-' . $n++;
        }
    }

    /** @return array{id:int,name:string,slug:string,components:string[]} */
    private static function hydrate(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'slug' => (string) $r->slug,
            'components' => self::cleanSlugs(
                explode(',', (string) ($r->components ?? ''))
            ),
        ];
    }
}
