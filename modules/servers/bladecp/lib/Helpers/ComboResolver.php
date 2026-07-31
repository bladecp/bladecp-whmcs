<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Resolve os slugs do combo atribuído a um produto WHMCS, lidos das
 * tabelas do addon de combos.
 *
 * Acoplamento intencional e mínimo: o server module NÃO depende do
 * CÓDIGO do addon — só dos NOMES de tabela (`mod_bladecp_product_combos`
 * / `mod_bladecp_combos`, documentados em
 * `modules/addons/bladecp/lib/Combos.php`). Assim o provisioning
 * consome o combo quando o addon o criou, sem exigir o addon: combo
 * é OPCIONAL — addon não ativado / tabela ausente → fail-soft `[]`,
 * o módulo provisiona só com os componentes avulsos do produto.
 *
 * Fail-soft total: sem Capsule, tabela ou linha, retorna `[]`.
 */
final class ComboResolver
{
    private const T_PRODUCT = 'mod_bladecp_product_combos';
    private const T_COMBOS = 'mod_bladecp_combos';

    /**
     * Slugs do combo do produto (`[]` se sem combo / fail-soft).
     *
     * @return string[]
     */
    public static function slugsForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return [];
            }
            $cap = \WHMCS\Database\Capsule::class;
            $link = $cap::table(self::T_PRODUCT)
                ->where('product_id', $productId)
                ->first();
            if ($link === null) {
                return [];
            }
            $combo = $cap::table(self::T_COMBOS)
                ->where('id', (int) $link->combo_id)
                ->first();
            if ($combo === null) {
                return [];
            }
            return self::splitSlugs((string) ($combo->components ?? ''));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Nome comercial do combo atribuído ao produto, sem expor ids internos. */
    public static function nameForProduct(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return '';
            }
            $cap = \WHMCS\Database\Capsule::class;
            $link = $cap::table(self::T_PRODUCT)
                ->where('product_id', $productId)
                ->first();
            if ($link === null) {
                return '';
            }
            $combo = $cap::table(self::T_COMBOS)
                ->where('id', (int) $link->combo_id)
                ->first();
            return trim((string) ($combo->name ?? ''));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Slugs de um combo pelo nome (combo escolhido pelo cliente
     * no dropdown "Combo" do order form). Acoplamento só por nome de
     * tabela (não depende do código do addon), fail-soft `[]`.
     *
     * @return string[]
     */
    public static function slugsForComboName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return [];
            }
            $cap = \WHMCS\Database\Capsule::class;
            $combo = $cap::table(self::T_COMBOS)
                ->where('name', $name)
                ->first();
            if ($combo === null) {
                return [];
            }
            return self::splitSlugs((string) ($combo->components ?? ''));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Todos os combos (nome → tokens `slug@versionId`) para o
     * progressive enhancement do order form (combo dirige os selects).
     * Acoplamento só por nome de tabela, fail-soft `[]`.
     *
     * @return array<string,string[]> nome do combo → tokens
     */
    public static function allCombos(): array
    {
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return [];
            }
            $cap = \WHMCS\Database\Capsule::class;
            $out = [];
            foreach ($cap::table(self::T_COMBOS)->get() as $c) {
                $name = trim((string) ($c->name ?? ''));
                if ($name === '' || isset($out[$name])) {
                    continue;
                }
                $out[$name] = self::splitSlugs(
                    (string) ($c->components ?? '')
                );
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return string[] */
    private static function splitSlugs(string $csv): array
    {
        $out = [];
        foreach (explode(',', $csv) as $s) {
            $s = trim($s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }
}
