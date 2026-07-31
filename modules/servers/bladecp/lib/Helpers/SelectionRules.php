<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Regras de seleção de componentes aplicadas de forma consistente pelo
 * módulo. São convenções de interface, não respostas da API; a fonte única
 * fica aqui,
 * consumida pelo combo builder (addon, via ponte autoload), pelo
 * gerador de Configurable Options e pela validação em
 * `_CreateAccount`. As regras são puras e recebem a categoria
 * de cada item (o catálogo resolve slug→categoria).
 *
 *  Uma por categoria (`type`): não pode 2 da mesma categoria
 *       (PHP+Python = Runtime; MySQL+MariaDB = Database).
 *  `apps`/`monitoring` são exclusivos: se houver um, é o único
 *       (appliance isolado).
 *
 */
final class SelectionRules
{
    /** Categorias solo (nomes do /setup, comparados lowercase). */
    public const SOLO = ['apps', 'monitoring'];

    /** Ordem de exibição das categorias no combo e no order form. */
    public const CATEGORY_ORDER = [
        'webservers', 'databases', 'languages',
        'cache', 'monitoring', 'apps',
    ];

    public static function isSolo(string $category): bool
    {
        return in_array(
            strtolower(trim($category)),
            self::SOLO,
            true
        );
    }

    /**
     * Rank de ordenação da categoria (CATEGORY_ORDER; fora da lista
     * vai pro fim, em ordem alfabética estável). Fonte ÚNICA usada
     * pelo combo picker, pelo diff do order form e pelo desiredSpec.
     */
    public static function categoryRank(string $category): int
    {
        $i = array_search(
            strtolower(trim($category)),
            self::CATEGORY_ORDER,
            true
        );
        return $i === false ? count(self::CATEGORY_ORDER) : $i;
    }

    /**
     * Valida o conjunto. `errors` vazio = ok. Mensagens humanas
     * (mostradas ao admin no combo / ao cliente no provisioning).
     *
     * @param array<int,array{slug:string,category:string}> $items
     * @return array{ok:bool,errors:string[]}
     */
    public static function check(array $items): array
    {
        $errors = [];
        $byCat = [];
        $solo = [];
        foreach ($items as $it) {
            $slug = trim((string) ($it['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $cat = trim((string) ($it['category'] ?? ''));
            if (self::isSolo($cat)) {
                $solo[] = $slug;
            }
            if ($cat !== '') {
                $byCat[strtolower($cat)][$slug] = $cat;
            }
        }
        // Uma seleção por categoria.
        foreach ($byCat as $list) {
            if (count($list) > 1) {
                $names = array_keys($list);
                $catLabel = reset($list);
                $errors[] = 'Categoria "' . $catLabel . '" aceita só um '
                    . 'componente — escolhidos: ' . implode(', ', $names) . '.';
            }
        }
        // Apps e monitoring são exclusivos de qualquer outra seleção.
        $total = 0;
        foreach ($items as $it) {
            if (trim((string) ($it['slug'] ?? '')) !== '') {
                $total++;
            }
        }
        if ($solo !== [] && ($total > 1 || count($solo) > 1)) {
            $errors[] = 'Componente(s) "' . implode(', ', array_unique($solo))
                . '" (Apps/Monitoring) é exclusivo — não pode junto com '
                . 'outros componentes.';
        }
        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Normaliza para um conjunto válido:
     *  - Havendo seleção exclusiva, fica só a primeira.
     *  - Por categoria, fica a última (nova seleção substitui a
     *        mesma categoria — igual ao toggleComponent).
     * Itens com categoria vazia ficam (cada um único). Preserva a
     * ordem de primeira aparição dos que sobram.
     *
     * @param array<int,array{slug:string,category:string}> $items
     * @return array<int,array{slug:string,category:string}>
     */
    public static function normalize(array $items): array
    {
        $clean = [];
        foreach ($items as $it) {
            $slug = trim((string) ($it['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $clean[] = [
                'slug' => $slug,
                'category' => trim((string) ($it['category'] ?? '')),
            ];
        }
        // A primeira seleção exclusiva vence todas as demais.
        foreach ($clean as $it) {
            if (self::isSolo($it['category'])) {
                return [$it];
            }
        }
        // Último por categoria; categoria vazia = único por slug.
        $lastByKey = [];
        foreach ($clean as $i => $it) {
            $key = $it['category'] !== ''
                ? 'c:' . strtolower($it['category'])
                : 's:' . $it['slug'];
            $lastByKey[$key] = $i;
        }
        $keep = array_flip($lastByKey); // índice => true
        $out = [];
        foreach ($clean as $i => $it) {
            if (isset($keep[$i])) {
                $out[] = $it;
            }
        }
        return $out;
    }

    /**
     * Indica se há uma escolha adicional que só pode ser interpretada com
     * um catálogo atual. Se o catálogo estiver indisponível, a escolha não
     * pode silenciosamente degradar para o plano base.
     *
     * @param array<string,mixed> $configoptions
     */
    public static function hasAdditionalSelection(array $configoptions): bool
    {
        $reserved = [
            'bladecp plan', 'location',
            'bladecp contract term months', 'contract term months',
            'bladecp contract term', 'contract term',
            'bladecp contract accepted', 'contract accepted',
            'bladecp contract acceptance', 'contract acceptance',
        ];
        foreach ($configoptions as $name => $value) {
            $key = strtolower(trim((string) $name));
            if (in_array($key, $reserved, true)) {
                continue;
            }
            if (self::selectionValueIsPresent($value)) {
                return true;
            }
        }
        return false;
    }

    private static function selectionValueIsPresent(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (float) $value > 0;
        }
        $value = strtolower(trim((string) $value));
        if ($value === '' || in_array($value, [
            '0', 'false', 'no', 'off', '— nenhum —', '— não incluir —',
        ], true)) {
            return false;
        }
        return true;
    }
}
