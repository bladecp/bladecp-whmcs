<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Camada de banco de dados e rede da importação de preços. Tudo fail-soft: sem
 * Capsule/Server record/rede → null/[]/false (offline-safe no
 * `PriceMap`).
 * aqui só I/O.
 *
 * `apply()` é opt-in/one-shot/explícito (chamado só após o admin ver
     * o diff e confirmar): grava apenas os ciclos que o plano
 * define, NUNCA zera/desabilita ciclo, NUNCA mexe em setup fee de
 * linha existente.
 */
final class Pricing
{
    /**
     * Memo por requisição dos planos do `/partner/plans` (a tela de preços
     * compara N produtos — sem isto seria 1 HTTP por produto). `null`
     * = ainda não buscado; `[]` = buscado e vazio/erro (não re-tenta).
     *
     * @var array<int,mixed>|null
     */
    private static ?array $plansMemo = null;

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    /** Plano BladeCP do produto = ConfigOption #2 (mesma posição que o ParamsMapper usa). */
    public static function planIdForProduct(int $pid): ?int
    {
        try {
            $db = self::db();
            if ($db === null) {
                return null;
            }
            $r = $db::table('tblproducts')->where('id', $pid)->first();
            $v = $r->configoption2 ?? null;
            return is_numeric($v) ? (int) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Produtos WHMCS ligados ao módulo BladeCP para a tela de preços (compara
     * preço × plataforma). `planId` = `configoption2` (mesmo contrato
     * do `planIdForProduct`/`ParamsMapper`). Fail-soft `[]`.
     *
     * @return array<int,array{pid:int,name:string,planId:?int}>
     */
    public static function bladecpProducts(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return [];
            }
            $out = [];
            foreach (
                $db::table('tblproducts')
                    ->where('servertype', 'bladecp')
                    ->orderBy('order')
                    ->orderBy('name')
                    ->get() as $r
            ) {
                $v = $r->configoption2 ?? null;
                $out[] = [
                    'pid' => (int) $r->id,
                    'name' => (string) ($r->name ?? ''),
                    'planId' => is_numeric($v) ? (int) $v : null,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,string> id => código (ex.: 1 => 'BRL') */
    public static function currencies(): array
    {
        try {
            $db = self::db();
            if ($db === null) {
                return [];
            }
            $out = [];
            foreach ($db::table('tblcurrencies')->get() as $c) {
                $out[(int) $c->id] = strtoupper((string) $c->code);
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Preço atual do produto numa moeda → `[col => ?float]` (≤0 = null).
     *
     * @return array<string,?float>
     */
    public static function currentCycles(int $pid, int $currencyId): array
    {
        $cols = array_values(\WHMCS\Module\Addon\Bladecp\PriceMap::MAP);
        $out = array_fill_keys($cols, null);
        try {
            $db = self::db();
            if ($db === null) {
                return $out;
            }
            $r = $db::table('tblpricing')
                ->where('type', 'product')
                ->where('relid', $pid)
                ->where('currency', $currencyId)
                ->first();
            if ($r === null) {
                return $out;
            }
            foreach ($cols as $c) {
                $v = $r->{$c} ?? null;
                $out[$c] = is_numeric($v) && (float) $v > 0
                    ? round((float) $v, 2)
                    : null;
            }
            return $out;
        } catch (\Throwable) {
            return $out;
        }
    }

    /**
     * Moedas do plano BladeCP (centavos) via Customer API. Fail-soft
     * `[]`. Casa o plano por `id` (mesmo contrato dos Loaders).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function planCurrencies(int $planId): array
    {
        try {
            foreach (self::allPlans() as $p) {
                if (!is_array($p) || (int) ($p['id'] ?? 0) !== $planId) {
                    continue;
                }
                $cur = $p['currencies'] ?? [];
                return is_array($cur) ? $cur : [];
            }
            return [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Linhas cruas do `/partner/plans` (memo por-request). Fail-soft
     * `[]`. A tela de preços compara vários produtos com uma única consulta.
     *
     * @return array<int,mixed>
     */
    public static function allPlans(): array
    {
        if (self::$plansMemo !== null) {
            return self::$plansMemo;
        }
        self::$plansMemo = [];
        try {
            if (!class_exists(ServerConfig::class)) {
                return self::$plansMemo;
            }
            $client = ServerConfig::client();
            if ($client === null) {
                return self::$plansMemo;
            }
            $rows = $client->get('/partner/plans');
            if (isset($rows['data']) && is_array($rows['data'])) {
                $rows = $rows['data'];
            }
            self::$plansMemo = is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            self::$plansMemo = [];
        }
        return self::$plansMemo;
    }

    /**
     * Grava SÓ as colunas de `$cycles` (ciclos que o plano define).
     * Linha existente: update parcial (não toca setup fee nem ciclos
     * fora de `$cycles`). Sem linha: cria com defaults WHMCS (ciclos
     * não definidos = -1 indisponível; setup fees = 0). Fail-soft.
     *
     * @param array<string,float> $cycles
     */
    public static function apply(int $pid, int $currencyId, array $cycles): bool
    {
        if ($cycles === []) {
            return false;
        }
        try {
            $db = self::db();
            if ($db === null) {
                return false;
            }
            $exists = $db::table('tblpricing')
                ->where('type', 'product')
                ->where('relid', $pid)
                ->where('currency', $currencyId)
                ->first();
            if ($exists !== null) {
                $db::table('tblpricing')
                    ->where('id', (int) $exists->id)
                    ->update($cycles);
                return true;
            }
            $allCols = array_values(\WHMCS\Module\Addon\Bladecp\PriceMap::MAP);
            $row = [
                'type' => 'product',
                'currency' => $currencyId,
                'relid' => $pid,
                'msetupfee' => 0,
                'qsetupfee' => 0,
                'ssetupfee' => 0,
                'asetupfee' => 0,
                'bsetupfee' => 0,
                'tsetupfee' => 0,
            ];
            foreach ($allCols as $c) {
                $row[$c] = $cycles[$c] ?? -1;
            }
            $db::table('tblpricing')->insert($row);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
