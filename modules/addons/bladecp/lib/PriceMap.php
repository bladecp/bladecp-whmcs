<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Mapeamento PURO preço do plano BladeCP → pricing de produto WHMCS
 * com importação opcional.
 *
 * Unidade do preço do plano: `admin_partner_plan_currencies` é
 * EXCEÇÃO à convenção "centavos" da plataforma — coluna `real`, em
 * UNIDADE de moeda (decimal): `29` = R$ 29,00; `82.65` = R$ 82,65
 * (confirmado pela API como valor que não usa centavos). WHMCS
 * `tblpricing` também guarda em unidade → mapeamento 1:1, SEM
 * divisão por 100 nem cast pra int (truncava o decimal). `-1`/0 no
 * WHMCS = ciclo indisponível → "sem valor" (null) no diff; a
 * sugestão nunca desabilita ciclo, apenas considera o que o plano define.
 */
final class PriceMap
{
    /** Campo de moeda do plano BladeCP → coluna `tblpricing`. */
    public const MAP = [
        'priceMonthly' => 'monthly',
        'priceQuarterly' => 'quarterly',
        'priceHalfYearly' => 'semiannually',
        'priceYearly' => 'annually',
        'priceTwoYears' => 'biennially',
        'priceThreeYears' => 'triennially',
    ];

    public const LABELS = [
        'monthly' => 'Mensal',
        'quarterly' => 'Trimestral',
        'semiannually' => 'Semestral',
        'annually' => 'Anual',
        'biennially' => 'Bienal',
        'triennially' => 'Trienal',
    ];

    /**
     * Ciclo → coluna de SETUP FEE do `tblpricing`. A Customer API
     * expõe UM `setupFee` por moeda (não por ciclo); `suggestedSetup`
     * aplica o mesmo valor a todas as colunas de setup (cobra uma vez
     * na compra, independente do ciclo escolhido). Usado pelas opções
     * configuráveis em `OptionGroups::ensureOptionPricing`; o preço-base
     * do plano continua sendo tratado por `MAP`, `amount` e `writable`.
     */
    public const SETUP_MAP = [
        'monthly' => 'msetupfee',
        'quarterly' => 'qsetupfee',
        'semiannually' => 'ssetupfee',
        'annually' => 'asetupfee',
        'biennially' => 'bsetupfee',
        'triennially' => 'tsetupfee',
    ];

    /**
     * Valor de moeda do plano (UNIDADE decimal, NÃO centavos) →
     * float 2c. null se ausente / não-numérico / ≤0. Sem ÷100 e
     * sem cast int (o preço já vem em reais, ex.: 82.65).
     */
    public static function amount(mixed $v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        $f = round((float) $v, 2);
        return $f > 0 ? $f : null;
    }

    /**
     * Linha de moeda do plano → `['code'=>STR,'cycles'=>[col=>?float]]`.
     *
     * @param array<string,mixed> $cur
     * @return array{code:string,cycles:array<string,?float>}
     */
    public static function suggested(array $cur): array
    {
        $cycles = [];
        foreach (self::MAP as $src => $dst) {
            $cycles[$dst] = self::amount($cur[$src] ?? null);
        }
        return [
            'code' => strtoupper(trim((string) ($cur['currency'] ?? ''))),
            'cycles' => $cycles,
        ];
    }

    /**
     * Linha de moeda (de uma OptionValue) → colunas de setup fee do
     * `tblpricing` (`['msetupfee'=>?float, ...]`). O `setupFee` único da
     * API é aplicado a todas as 6 colunas; ≤0/ausente → null (= 0 na
     * gravação). Esse mapeamento não altera o preço-base do plano.
     *
     * NOTA (optiontype=4 / quantity): o preço POR UNIDADE da opção de
     * quantidade vive nas MESMAS colunas de ciclo (`suggested`); não há
     * coluna especial — `suggested`/`suggestedSetup` já cobrem quantity.
     *
     * @param array<string,mixed> $cur
     * @return array<string,?float>
     */
    public static function suggestedSetup(array $cur): array
    {
        $fee = self::amount($cur['setupFee'] ?? null);
        $out = [];
        foreach (self::SETUP_MAP as $col) {
            $out[$col] = $fee;
        }
        return $out;
    }

    /**
     * Diff sugerido × atual — SÓ os ciclos que o plano define
     * (`new !== null`). `changed` quando difere ≥ 1 centavo ou não
     * havia preço.
     *
     * @param array<string,?float> $suggested
     * @param array<string,?float> $current
     * @return array<int,array{cycle:string,label:string,old:?float,new:float,changed:bool}>
     */
    public static function diff(array $suggested, array $current): array
    {
        $out = [];
        foreach ($suggested as $cyc => $new) {
            if ($new === null) {
                continue;
            }
            $old = $current[$cyc] ?? null;
            $out[] = [
                'cycle' => $cyc,
                'label' => self::LABELS[$cyc] ?? $cyc,
                'old' => $old,
                'new' => $new,
                'changed' => $old === null
                    || abs($old - $new) >= 0.005,
            ];
        }
        return $out;
    }

    /**
     * Só os pares coluna=>valor a gravar (ciclos definidos pelo
     * plano). Não inclui ciclo sem valor → não desabilita nada.
     *
     * @param array<string,?float> $suggestedCycles
     * @return array<string,float>
     */
    public static function writable(array $suggestedCycles): array
    {
        $out = [];
        foreach ($suggestedCycles as $col => $val) {
            if ($val !== null) {
                $out[$col] = $val;
            }
        }
        return $out;
    }
}
