<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\CacheStore;
use WHMCS\Module\Server\Bladecp\Helpers\ProvisionView;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Dados financeiros somente leitura do canal Marketplace.
 */
final class MarketplaceAccount
{
    /** Tamanho de página padrão dos extratos. */
    public const PAGE_SIZE = 25;

    public const COMMISSION_STATUSES = [
        'pending',
        'confirmed',
        'cleared',
        'reversed',
        'rejected',
    ];

    // ── I/O (fail-soft: erro/offline → null) ──

    /**
     * Repasse por moeda + cobertura de substrato + contadores.
     * `GET /customer/marketplace/summary` (prefixo `/customer/` anexado
     * pelo `Endpoints::url()` — passamos só `/marketplace/summary`).
     *
     * @return array{currencies:array<int,array<string,mixed>>,substrateCoverage:array<string,mixed>|null,activeListings:int,soldSubscriptions:int}|null
     */
    public static function summary(): ?array
    {
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            return self::parseSummary($client->get('/marketplace/summary'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Faturas de mensalidade do substrato em aberto (BRL).
     * `GET /customer/marketplace/substrate-invoices`.
     *
     * @return array{currency:string,explicit:bool,subscriptionLabel:string,totalCents:int,items:array<int,array<string,mixed>>}|null
     */
    public static function substrateInvoices(): ?array
    {
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            return self::parseSubstrate(
                $client->get('/marketplace/substrate-invoices')
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Extratos de fechamento (direção/status/NET).
     * `GET /customer/marketplace/statements`.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}|null
     */
    public static function statements(
        int $page = 1,
        int $pageSize = self::PAGE_SIZE
    ): ?array {
        $page = max(1, $page);
        $pageSize = $pageSize > 0 ? $pageSize : self::PAGE_SIZE;
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            return self::parseStatements($client->get(
                '/marketplace/statements?page=' . $page
                . '&pageSize=' . $pageSize
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Detalhe seller-scoped de um extrato. O ID numérico é apenas uma
     * referência; a Customer API aplica ownership e responde 404 para outro
     * partner.
     *
     * @return array{statement:array<string,mixed>,lines:list<array<string,mixed>>}|null
     */
    public static function statement(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            return self::parseStatementDetail($client->get(
                '/marketplace/statements/' . $id
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ledger seller-scoped, paginado e opcionalmente filtrado por estado.
     *
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}|null
     */
    public static function commissionLines(
        int $page = 1,
        int $pageSize = self::PAGE_SIZE,
        string $status = ''
    ): ?array {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $status = in_array($status, self::COMMISSION_STATUSES, true) ? $status : '';
        $query = [
            'page=' . $page,
            'pageSize=' . $pageSize,
        ];
        if ($status !== '') {
            $query[] = 'status=' . rawurlencode($status);
        }
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return null;
            }
            return self::parseCommissionLines(
                $client->get('/marketplace/commission-lines?' . implode('&', $query)),
                $page,
                $pageSize
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /** Cache curto (s) das 3 seções — reabrir a tela fica instantâneo. */
    private const CACHE_TTL = 60;

    /**
     * Carrega as 3 seções da conta de UMA vez: client resolvido **1×**,
     * os 3 GETs em **paralelo** (`curl_multi`) e resultado **cacheado**
     * (TTL curto). É o que a tela "Minha conta" deve usar — colapsa os 3
     * round-trips sequenciais em ~1 e evita re-fetch a cada abertura.
     *
     * @return array{summary:?array,substrate:?array,statements:?array}
     */
    public static function load(): array
    {
        $empty = ['summary' => null, 'substrate' => null, 'statements' => null];
        if (!class_exists(ServerConfig::class)) {
            return $empty;
        }
        try {
            $client = ServerConfig::client(); // 1× (não 3×)
            if ($client === null) {
                return $empty;
            }
            $cached = self::readCache($client);
            if ($cached !== null) {
                return $cached;
            }
            [$s, $sub, $st] = $client->getMany([
                '/marketplace/summary',
                '/marketplace/substrate-invoices',
                '/marketplace/statements?page=1&pageSize=' . self::PAGE_SIZE,
            ]);
            $out = [
                'summary' => $s !== null ? self::parseSummary($s) : null,
                'substrate' => $sub !== null ? self::parseSubstrate($sub) : null,
                'statements' => $st !== null ? self::parseStatements($st) : null,
            ];
            // Só cacheia se veio algo (não fixa "vazio" quando a API caiu).
            if ($out['summary'] !== null || $out['substrate'] !== null || $out['statements'] !== null) {
                self::writeCache($client, $out);
            }

            return $out;
        } catch (\Throwable) {
            return $empty;
        }
    }

    // ── cache curto (file-based, TTL) ──

    private static function readCache(Client $client): ?array
    {
        $d = CacheStore::read($client, 'bladecp_mkt_account_v1.json', self::CACHE_TTL);
        if (!is_array($d) || !array_key_exists('summary', $d)) {
            return null;
        }

        return [
            'summary' => is_array($d['summary'] ?? null) ? $d['summary'] : null,
            'substrate' => is_array($d['substrate'] ?? null) ? $d['substrate'] : null,
            'statements' => is_array($d['statements'] ?? null) ? $d['statements'] : null,
        ];
    }

    private static function writeCache(Client $client, array $out): void
    {
        CacheStore::write($client, 'bladecp_mkt_account_v1.json', $out);
    }

    // ── Helpers de dados ──

    /**
     * Pura: resposta → resumo normalizado. Money em centavos (int).
     * Aceita o objeto `{currencies,...}` (Client desembrulha `data` 1x) e
     * o envelope duplo `{data:{...}}` (defensivo). Moedas sem código são
     * ignoradas; `substrateCoverage` ausente → `null`.
     *
     * @return array{currencies:array<int,array<string,mixed>>,substrateCoverage:array<string,mixed>|null,activeListings:int,soldSubscriptions:int}
     */
    public static function parseSummary(mixed $response): array
    {
        $out = [
            'currencies' => [],
            'substrateCoverage' => null,
            'activeListings' => 0,
            'soldSubscriptions' => 0,
        ];
        if (!is_array($response)) {
            return $out;
        }
        $response = self::unwrap($response, [
            'currencies',
            'substrateCoverage',
            'activeListings',
            'soldSubscriptions',
        ]);

        $curs = $response['currencies'] ?? null;
        if (is_array($curs)) {
            foreach ($curs as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($c['currency'] ?? '')));
                if ($code === '') {
                    continue;
                }
                $out['currencies'][] = [
                    'currency' => $code,
                    'pendingCents' => self::cents($c['pendingCents'] ?? null),
                    'confirmedCents' => self::cents($c['confirmedCents'] ?? null),
                    'clearedCents' => self::cents($c['clearedCents'] ?? null),
                    'lifetimeNetPayableCents' => self::cents(
                        $c['lifetimeNetPayableCents'] ?? null
                    ),
                ];
            }
        }

        $cov = $response['substrateCoverage'] ?? null;
        if (is_array($cov)) {
            $break = $cov['breakEvenSalesRemaining'] ?? null;
            $out['substrateCoverage'] = [
                'substrateMonthlyCents' => self::cents(
                    $cov['substrateMonthlyCents'] ?? null
                ),
                'coveredCents' => self::cents($cov['coveredCents'] ?? null),
                'remainingCents' => self::cents($cov['remainingCents'] ?? null),
                'breakEvenSalesRemaining' => is_numeric($break)
                    ? (int) $break
                    : null,
            ];
        }

        $out['activeListings'] = is_numeric($response['activeListings'] ?? null)
            ? (int) $response['activeListings']
            : 0;
        $out['soldSubscriptions'] = is_numeric($response['soldSubscriptions'] ?? null)
            ? (int) $response['soldSubscriptions']
            : 0;
        return $out;
    }

    /**
     * Pura: resposta → faturas de substrato normalizadas. Linhas sem
     * identificador (`invoiceId`/`invoiceNumber`) são puladas.
     *
     * @return array{currency:string,explicit:bool,subscriptionLabel:string,totalCents:int,items:array<int,array<string,mixed>>}
     */
    public static function parseSubstrate(mixed $response): array
    {
        $out = [
            'currency' => 'BRL',
            'explicit' => false,
            'subscriptionLabel' => '',
            'totalCents' => 0,
            'items' => [],
        ];
        if (!is_array($response)) {
            return $out;
        }
        $response = self::unwrap($response, [
            'items',
            'totalCents',
            'currency',
            'subscriptionLabel',
        ]);

        $code = strtoupper(trim((string) ($response['currency'] ?? '')));
        $out['currency'] = $code !== '' ? $code : 'BRL';
        $out['explicit'] = !empty($response['explicit']);
        $out['subscriptionLabel'] = trim(
            (string) ($response['subscriptionLabel'] ?? '')
        );
        $out['totalCents'] = self::cents($response['totalCents'] ?? null);

        $raw = $response['items'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['invoiceId'] ?? ''));
                $num = trim((string) ($row['invoiceNumber'] ?? ''));
                if ($id === '' && $num === '') {
                    continue;
                }
                $out['items'][] = [
                    'invoiceId' => $id,
                    'invoiceNumber' => $num,
                    'subscriptionLabel' => trim(
                        (string) ($row['subscriptionLabel'] ?? '')
                    ),
                    'totalCents' => self::cents($row['totalCents'] ?? null),
                    'status' => trim((string) ($row['status'] ?? '')),
                    'dueDate' => trim((string) ($row['dueDate'] ?? '')),
                ];
            }
        }
        return $out;
    }

    /**
     * Pura: resposta → extratos normalizados. Aceita `{items,total}`,
     * o envelope duplo e a lista crua. Linhas sem `id` são puladas.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function parseStatements(mixed $response): array
    {
        $out = ['items' => [], 'total' => 0];
        if (!is_array($response)) {
            return $out;
        }
        $response = self::unwrap($response, ['items', 'total']);
        $raw = $response['items'] ?? null;
        if (!is_array($raw)) {
            $raw = array_is_list($response) ? $response : [];
        }
        foreach ($raw as $row) {
            $st = self::parseStatement($row);
            if ($st !== null) {
                $out['items'][] = $st;
            }
        }
        $out['total'] = is_numeric($response['total'] ?? null)
            ? (int) $response['total']
            : count($out['items']);
        return $out;
    }

    /**
     * Pura: 1 linha de extrato → shape normalizado. Money em centavos
     * (int; pode ser negativo — clawback). `null` sem `id`.
     *
     * @return array<string,mixed>|null
     */
    public static function parseStatement(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $id = trim((string) ($row['id'] ?? ''));
        if ($uuid === '' && $id === '') {
            return null;
        }
        $paidAt = trim((string) ($row['paidAt'] ?? ''));
        return [
            'uuid' => $uuid !== '' ? $uuid : $id,
            'id' => $id,
            'periodStart' => trim((string) ($row['periodStart'] ?? '')),
            'periodEnd' => trim((string) ($row['periodEnd'] ?? '')),
            'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))),
            'totalGrossCents' => self::cents($row['totalGrossCents'] ?? null),
            'totalCommissionCents' => self::cents(
                $row['totalCommissionCents'] ?? null
            ),
            'totalNetPayableCents' => self::cents(
                $row['totalNetPayableCents'] ?? null
            ),
            'substrateInvoiceCents' => self::cents(
                $row['substrateInvoiceCents'] ?? null
            ),
            'settlementNetCents' => self::cents(
                $row['settlementNetCents'] ?? null
            ),
            'direction' => trim((string) ($row['direction'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'paidAt' => $paidAt,
            'paidAtLabel' => ProvisionView::formatDate($paidAt),
        ];
    }

    /**
     * @return array{statement:array<string,mixed>,lines:list<array<string,mixed>>}|null
     */
    public static function parseStatementDetail(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }
        $response = self::unwrap(
            $response,
            ['statement', 'lines', 'uuid', 'id']
        );
        $statement = self::parseStatement(
            is_array($response['statement'] ?? null)
                ? $response['statement']
                : $response
        );
        if ($statement === null) {
            return null;
        }
        $lines = [];
        $raw = $response['lines'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            $line = self::parseCommissionLine($row);
            if ($line !== null) {
                if ($line['currency'] === '') {
                    $line['currency'] = $statement['currency'];
                }
                $lines[] = $line;
            }
        }
        return ['statement' => $statement, 'lines' => $lines];
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,page:int,pageSize:int}
     */
    public static function parseCommissionLines(
        mixed $response,
        int $page = 1,
        int $pageSize = self::PAGE_SIZE
    ): array {
        $out = [
            'items' => [],
            'total' => 0,
            'page' => max(1, $page),
            'pageSize' => max(1, $pageSize),
        ];
        if (!is_array($response)) {
            return $out;
        }
        $response = self::unwrap($response, ['items', 'total', 'page', 'pageSize']);
        $raw = $response['items'] ?? [];
        foreach (is_array($raw) ? $raw : [] as $row) {
            $line = self::parseCommissionLine($row);
            if ($line !== null) {
                $out['items'][] = $line;
            }
        }
        $out['total'] = is_numeric($response['total'] ?? null)
            ? max(0, (int) $response['total']) : count($out['items']);
        $out['page'] = is_numeric($response['page'] ?? null)
            ? max(1, (int) $response['page']) : $out['page'];
        $out['pageSize'] = is_numeric($response['pageSize'] ?? null)
            ? max(1, (int) $response['pageSize']) : $out['pageSize'];
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function parseCommissionLine(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $uuid = trim((string) ($row['uuid'] ?? ''));
        $id = trim((string) ($row['id'] ?? ''));
        if ($uuid === '' && $id === '') {
            return null;
        }
        $accruedAt = trim((string) ($row['accruedAt'] ?? ''));
        $clearedAt = trim((string) ($row['clearedAt'] ?? ''));
        $netPayable = array_key_exists('netPayableCents', $row)
            ? $row['netPayableCents']
            : ($row['netCents'] ?? null);
        return [
            'uuid' => $uuid !== '' ? $uuid : $id,
            'id' => $id !== '' ? $id : $uuid,
            'subscriptionId' => trim((string) ($row['subscriptionId'] ?? '')),
            'provisionUuid' => trim((string) ($row['provisionUuid'] ?? '')),
            'invoiceId' => trim((string) ($row['invoiceId'] ?? '')),
            'productName' => trim((string) ($row['productName'] ?? '')),
            'currency' => strtoupper(trim((string) ($row['currency'] ?? ''))),
            'source' => trim((string) ($row['source'] ?? '')),
            'kind' => trim((string) ($row['kind'] ?? '')),
            'amountCents' => self::cents($row['amountCents'] ?? null),
            'grossCents' => self::cents($row['grossCents'] ?? null),
            'commissionCents' => self::cents($row['commissionCents'] ?? null),
            'netCents' => self::cents($row['netCents'] ?? null),
            'netPayableCents' => self::cents($netPayable),
            'paymentMethod' => trim((string) ($row['paymentMethod'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'accruedAt' => $accruedAt,
            'accruedAtLabel' => ProvisionView::formatDate($accruedAt),
            'clearedAt' => $clearedAt,
            'clearedAtLabel' => ProvisionView::formatDate($clearedAt),
        ];
    }

    /**
     * Defensivo contra wrapper duplo `{data:{...}}`: o Client já
     * desembrulha `data` uma vez, mas algumas respostas podem
     * trazer a forma envelopada. Só desembrulha se `data` for um objeto
     * (não-lista) que contenha ao menos uma das chaves esperadas.
     *
     * @param array<mixed> $r
     * @param array<int,string> $keys
     * @return array<mixed>
     */
    private static function unwrap(array $r, array $keys): array
    {
        $data = $r['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            return $r;
        }
        foreach ($keys as $k) {
            if (array_key_exists($k, $data)) {
                return $data;
            }
        }
        return $r;
    }

    /** Centavos como int (negativo preservado p/ clawback); garbage → 0. */
    private static function cents(mixed $v): int
    {
        return is_numeric($v) ? (int) round((float) $v) : 0;
    }
}
