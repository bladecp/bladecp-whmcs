<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\ProvisionView;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Dados somente leitura do canal Marketplace.
 */
final class Marketplace
{
    /** Tamanho de página padrão. */
    public const PAGE_SIZE = 25;

    /**
     * Pedidos vendidos atribuídos ao partner (seller-scoped). Fail-soft:
     * sem Server record / key / rede / catálogo → `null`.
     *
     * Endpoint = `GET /customer/marketplace/orders` (mesmo serviço
     * de pedidos do marketplace). O prefixo `/customer/`
     * é anexado pelo `Endpoints::url()` — por isso passamos só
     * `/marketplace/orders` aqui (passar `/customer/...` DUPLICARIA o
     * prefixo).
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pageSize:int}|null
     */
    public static function orders(int $page = 1, int $pageSize = self::PAGE_SIZE): ?array
    {
        $page = max(1, $page);
        $pageSize = $pageSize > 0 ? $pageSize : self::PAGE_SIZE;
        try {
            if (!class_exists(ServerConfig::class)) {
                return null;
            }
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            $resp = $client->get(
                '/marketplace/orders?page=' . $page . '&pageSize=' . $pageSize
            );
            return self::parseOrders($resp, $page, $pageSize);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Pura: resposta da API → estrutura normalizada de pedidos.
     * Aceita o objeto `{items,total,page,pageSize}` (Client já
     * desembrulha `data` 1x), o envelope `{data:{items,...}}` e a
     * lista crua (fallback defensivo). Linhas sem identificador
     * (`subscriptionId`/`provisionUuid`) são puladas.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pageSize:int}
     */
    public static function parseOrders(
        mixed $response,
        int $page = 1,
        int $pageSize = self::PAGE_SIZE
    ): array {
        $out = [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
        if (!is_array($response)) {
            return $out;
        }
        // Defensivo contra wrapper duplo: `{data:{items,...}}`. O Client
        // Alguns formatos de resposta já vêm envolvidos por `data`.
        // podem trazer a forma envelopada.
        if (
            isset($response['data'])
            && is_array($response['data'])
            && !array_is_list($response['data'])
            && (
                isset($response['data']['items'])
                || isset($response['data']['total'])
            )
        ) {
            $response = $response['data'];
        }

        $rawItems = $response['items'] ?? null;
        if (!is_array($rawItems)) {
            // Resposta pode ser a própria lista crua de pedidos.
            $rawItems = array_is_list($response) ? $response : [];
        }

        $items = [];
        foreach ($rawItems as $row) {
            $order = self::parseOrder($row);
            if ($order !== null) {
                $items[] = $order;
            }
        }
        $out['items'] = $items;
        $out['total'] = isset($response['total']) && is_numeric($response['total'])
            ? (int) $response['total']
            : count($items);
        $out['page'] = isset($response['page']) && is_numeric($response['page'])
            ? (int) $response['page']
            : $page;
        $out['pageSize'] = isset($response['pageSize']) && is_numeric($response['pageSize'])
            ? (int) $response['pageSize']
            : $pageSize;
        return $out;
    }

    /**
     * Pura: 1 linha de pedido → shape normalizado do contrato. Money
     * em centavos (int). `soldAtLabel` = data pt-BR fail-soft via
     * `ProvisionView::formatDate` (fonte única de formatação de data).
     * `null` quando a linha não tem identificador algum.
     *
     * @return array{subscriptionId:string,provisionUuid:string,customerName:string,customerEmail:string,productName:string,planName:string,currency:string,retailCents:int,commissionCents:int,netCents:int,status:string,soldAt:string,soldAtLabel:string}|null
     */
    public static function parseOrder(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $sub = trim((string) ($row['subscriptionId'] ?? ''));
        $uuid = trim((string) ($row['provisionUuid'] ?? ''));
        if ($sub === '' && $uuid === '') {
            return null;
        }
        $soldAt = trim((string) ($row['soldAt'] ?? ''));
        return [
            'subscriptionId' => $sub,
            'provisionUuid' => $uuid,
            'customerName' => trim((string) ($row['customerName'] ?? '')),
            'customerEmail' => trim((string) ($row['customerEmail'] ?? '')),
            'productName' => trim((string) ($row['productName'] ?? '')),
            'planName' => trim((string) ($row['planName'] ?? '')),
            'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))),
            'retailCents' => self::cents($row['retailCents'] ?? null),
            'commissionCents' => self::cents($row['commissionCents'] ?? null),
            'netCents' => self::cents($row['netCents'] ?? null),
            'status' => trim((string) ($row['status'] ?? '')),
            'soldAt' => $soldAt,
            'soldAtLabel' => ProvisionView::formatDate($soldAt),
        ];
    }

    /**
     * Pura: agrega os pedidos por MOEDA (contagem + somas em centavos).
     * NUNCA soma moedas diferentes — cada moeda é uma chave própria.
     * Linhas sem moeda são ignoradas no resumo. Ordenado por código.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array{count:int,retailCents:int,commissionCents:int,netCents:int}>
     */
    public static function summarize(array $items): array
    {
        $byCur = [];
        foreach ($items as $o) {
            $code = strtoupper(trim((string) ($o['currency'] ?? '')));
            if ($code === '') {
                continue;
            }
            if (!isset($byCur[$code])) {
                $byCur[$code] = [
                    'count' => 0,
                    'retailCents' => 0,
                    'commissionCents' => 0,
                    'netCents' => 0,
                ];
            }
            $byCur[$code]['count']++;
            $byCur[$code]['retailCents'] += (int) ($o['retailCents'] ?? 0);
            $byCur[$code]['commissionCents'] += (int) ($o['commissionCents'] ?? 0);
            $byCur[$code]['netCents'] += (int) ($o['netCents'] ?? 0);
        }
        ksort($byCur);
        return $byCur;
    }

    /** Centavos como int (>=0); não-numérico/negativo-garbage → 0. */
    private static function cents(mixed $v): int
    {
        return is_numeric($v) ? (int) round((float) $v) : 0;
    }
}
