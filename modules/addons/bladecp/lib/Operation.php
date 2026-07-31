<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Leitura operacional do módulo para a área administrativa.
 *
 * Esta classe não cria tabelas, não chama a API e não altera serviços. Ela
 * trabalha somente com as capacidades que já existem no WHMCS instalado e
 * degrada para coleções vazias quando uma tabela ou coluna não estiver
 * disponível. Isso permite usar a mesma tela em instalações antigas e novas.
 */
final class Operation
{
    public const PAGE_SIZE = 25;
    public const MAX_LOG_ITEMS = 30;

    /** @var list<string> */
    private const SERVICE_STATUSES = [
        'Pending',
        'Active',
        'Suspended',
        'Terminated',
        'Cancelled',
        'Fraud',
    ];

    /**
     * Dados completos, mas compactos, para uma visão operacional read-only.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public static function load(array $filters = []): array
    {
        $normalized = self::filters($filters);
        $scope = self::managementScope();
        $serverId = $scope['serverId'] ?? null;
        $serverGroupId = $scope['serverGroupId'] ?? null;
        return [
            'capabilities' => self::capabilities(),
            'filters' => $normalized,
            'summary' => self::summary($serverId, $serverGroupId),
            'services' => self::services($normalized, $serverId, $serverGroupId),
            // Em versões que expõem serviceid, o log é limitado aos serviços
            // do contexto selecionado. Sem esse vínculo, a coleção fica vazia.
            'failures' => $serverId === null
                ? [] : self::moduleLogEntries('failure', self::MAX_LOG_ITEMS, $serverId, $serverGroupId),
            'webhooks' => self::recentWebhooks(12, $serverId),
            'synchronizations' => $serverId === null
                ? [] : self::moduleLogEntries('sync', 15, $serverId, $serverGroupId),
        ];
    }

    /**
     * @return array{database:bool,services:bool,moduleLog:bool,webhooks:bool}
     */
    public static function capabilities(): array
    {
        $database = class_exists(\WHMCS\Database\Capsule::class);
        return [
            'database' => $database,
            'services' => $database && self::hasTable('tblhosting')
                && self::hasTable('tblproducts'),
            'moduleLog' => $database && self::hasTable('tblmodulelog'),
            'webhooks' => $database && (
                self::hasTable(WebhookEventStore::TABLE)
                || self::hasTable('mod_bladecp_webhook_events')
            ),
        ];
    }

    /**
     * @return array{total:int,active:int,pending:int,suspended:int,attention:int}
     */
    public static function summary(?int $serverId = null, ?int $serverGroupId = null): array
    {
        $empty = ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0, 'attention' => 0];
        $scope = $serverId !== null && $serverGroupId !== null
            ? ['serverId' => $serverId, 'serverGroupId' => $serverGroupId]
            : self::managementScope();
        if ($scope === null) {
            return $empty;
        }
        if (!self::capabilities()['services']) {
            return $empty;
        }

        try {
            $row = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('p.servertype', 'bladecp')
                ->where('h.server', $scope['serverId'])
                ->where('p.servergroup', $scope['serverGroupId'])
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN h.domainstatus = 'Active' THEN 1 ELSE 0 END) as active")
                ->selectRaw("SUM(CASE WHEN h.domainstatus = 'Pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN h.domainstatus = 'Suspended' THEN 1 ELSE 0 END) as suspended")
                ->selectRaw("SUM(CASE WHEN h.domainstatus IN ('Pending', 'Suspended', 'Fraud') THEN 1 ELSE 0 END) as attention")
                ->first();
            if ($row === null) {
                return $empty;
            }
            foreach ($empty as $key => $value) {
                $empty[$key] = max(0, (int) ($row->{$key} ?? $value));
            }
        } catch (\Throwable) {
            // Mantém a tela utilizável quando uma versão do WHMCS divergir.
        }
        return $empty;
    }

    /**
     * @param array{status:string,query:string,page:int,perPage:int} $filters
     * @return array{items:list<array<string,mixed>>,page:int,perPage:int,total:int}
     */
    public static function services(array $filters = [], ?int $serverId = null, ?int $serverGroupId = null): array
    {
        $filters = self::filters($filters);
        $empty = [
            'items' => [],
            'page' => $filters['page'],
            'perPage' => $filters['perPage'],
            'total' => 0,
        ];
        $scope = $serverId !== null && $serverGroupId !== null
            ? ['serverId' => $serverId, 'serverGroupId' => $serverGroupId]
            : self::managementScope();
        if ($scope === null) {
            return $empty;
        }
        if (!self::capabilities()['services']) {
            return $empty;
        }

        try {
            $base = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('p.servertype', 'bladecp')
                ->where('h.server', $scope['serverId'])
                ->where('p.servergroup', $scope['serverGroupId']);
            if ($filters['status'] !== '') {
                $base->where('h.domainstatus', $filters['status']);
            }
            if ($filters['query'] !== '') {
                $like = '%' . $filters['query'] . '%';
                $base->where(static function ($query) use ($like): void {
                    $query->where('h.id', 'like', $like)
                        ->orWhere('h.domain', 'like', $like)
                        ->orWhere('p.name', 'like', $like);
                });
            }

            $total = (int) (clone $base)->count();
            $rows = $base
                ->select([
                    'h.id as serviceId',
                    'h.domainstatus as status',
                    'h.domain as domain',
                    'h.server as serverId',
                    'h.regdate as createdAt',
                    'h.nextduedate as nextDueDate',
                    'p.id as productId',
                    'p.name as productName',
                    'p.configoption1 as productType',
                    'p.configoption2 as planId',
                ])
                ->orderByDesc('h.id')
                ->forPage($filters['page'], $filters['perPage'])
                ->get();

            foreach ($rows as $row) {
                $empty['items'][] = [
                    'serviceId' => (int) ($row->serviceId ?? 0),
                    'status' => self::serviceStatus((string) ($row->status ?? '')),
                    'domain' => self::plain((string) ($row->domain ?? ''), 150),
                    'serverId' => max(0, (int) ($row->serverId ?? 0)),
                    'createdAt' => self::date((string) ($row->createdAt ?? '')),
                    'nextDueDate' => self::date((string) ($row->nextDueDate ?? '')),
                    'productId' => (int) ($row->productId ?? 0),
                    'productName' => self::plain((string) ($row->productName ?? ''), 150),
                    'productType' => self::plain((string) ($row->productType ?? ''), 50),
                    'planId' => self::plain((string) ($row->planId ?? ''), 100),
                ];
            }
            $empty['total'] = max(0, $total);
        } catch (\Throwable) {
            return $empty;
        }
        return $empty;
    }

    /**
     * Eventos recentes do log nativo, redigidos e deliberadamente pequenos.
     *
     * @return list<array{at:string,action:string,status:string,message:string}>
     */
    public static function moduleLogEntries(
        string $kind = '',
        int $limit = self::MAX_LOG_ITEMS,
        ?int $serverId = null,
        ?int $serverGroupId = null
    ): array
    {
        if (!self::capabilities()['moduleLog']) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        try {
            $columns = self::columns('tblmodulelog');
            if (!in_array('module', $columns, true)) {
                return [];
            }
            $serviceIds = [];
            if ($serverId !== null) {
                if (!in_array('serviceid', $columns, true)) {
                    return [];
                }
                if ($serverGroupId === null) {
                    return [];
                }
                $serviceIds = self::serviceIdsForServer($serverId, $serverGroupId);
                if ($serviceIds === []) {
                    return [];
                }
            }
            $select = array_values(array_intersect(
                ['id', 'date', 'module', 'action', 'error', 'serviceid'],
                $columns
            ));
            if ($select === []) {
                return [];
            }
            $query = \WHMCS\Database\Capsule::table('tblmodulelog')
                ->where('module', 'bladecp')
                ->orderByDesc(in_array('id', $columns, true) ? 'id' : 'date')
                ->limit(100)
                ->select($select);
            if ($serviceIds !== []) {
                $query->whereIn('serviceid', $serviceIds);
            }
            $out = [];
            foreach ($query->get() as $row) {
                $action = self::plain((string) ($row->action ?? ''), 160);
                $failed = trim((string) ($row->error ?? '')) !== '';
                if ($kind === 'failure' && !$failed) {
                    continue;
                }
                if ($kind === 'sync' && !preg_match('/sync|import|catalog|combo|option/i', $action)) {
                    continue;
                }
                $out[] = [
                    'at' => self::date((string) ($row->date ?? '')),
                    'action' => $action !== '' ? $action : 'Ação BladeCP',
                    'status' => $failed ? 'failed' : 'recorded',
                    'message' => $failed
                        ? 'Falha registrada. Consulte o log do módulo para os detalhes técnicos.'
                        : 'Ação registrada pelo módulo.',
                ];
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A tabela de dedupe registra apenas que o receiver viu o evento. Não há
     * payload nem segredo nela, portanto não inferimos uma entrega concluída.
     *
     * @return list<array{at:string,status:string,message:string}>
     */
    public static function recentWebhooks(int $limit = 12, ?int $serverId = null): array
    {
        $scope = self::managementScope();
        $serverId ??= $scope['serverId'] ?? null;
        if ($serverId === null || $scope === null) {
            return [];
        }
        if (!self::capabilities()['webhooks']) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        $ledger = WebhookEventStore::recent($limit, $serverId);
        if ($ledger !== []) {
            $out = [];
            foreach ($ledger as $row) {
                $status = self::plain((string) ($row['status'] ?? ''), 16);
                $event = self::plain((string) ($row['event'] ?? ''), 100);
                $attempts = max(0, (int) ($row['attempts'] ?? 0));
                $out[] = [
                    'at' => self::date((string) ($row['at'] ?? '')),
                    'status' => $status !== '' ? $status : 'received',
                    'message' => ($event !== '' ? $event : 'Evento BladeCP')
                        . ($attempts > 1 ? ' (' . $attempts . ' tentativas)' : ''),
                ];
            }
            return $out;
        }
        // O ledger legado não possui server_id. Exibi-lo aqui misturaria
        // contas administrativas distintas, portanto o fallback é vazio.
        return [];
    }

    /** @return array{status:string,query:string,page:int,perPage:int} */
    public static function filters(array $input): array
    {
        $status = trim((string) ($input['status'] ?? ''));
        return [
            'status' => in_array($status, self::SERVICE_STATUSES, true) ? $status : '',
            'query' => self::plain((string) ($input['query'] ?? ''), 100),
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'perPage' => max(10, min(50, (int) ($input['perPage'] ?? self::PAGE_SIZE))),
        ];
    }

    public static function redact(string $value): string
    {
        $value = preg_replace('/\b(?:whsec_|(?:bcp|vh)_(?:live|sand)_)[A-Za-z0-9._-]+/i', '***REDACTED***', $value) ?? '';
        $value = preg_replace('/(Authorization\s*[:=]\s*(?:Bearer|Basic)\s+)[^\s,;"}]+/i', '$1***REDACTED***', $value) ?? '';
        $value = preg_replace('/("?(?:api[_-]?key|access[_-]?hash|secret|password)"?\s*[:=]\s*)[^,\s"}]+/i', '$1***REDACTED***', $value) ?? '';
        return trim($value);
    }

    private static function serviceStatus(string $value): string
    {
        return in_array($value, self::SERVICE_STATUSES, true) ? $value : 'Unknown';
    }

    private static function plain(string $value, int $limit): string
    {
        $value = trim(strip_tags(self::redact($value)));
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $limit)
            : substr($value, 0, $limit);
    }

    private static function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', trim($value))
            ? trim($value) : '';
    }

    /**
     * Operações globais só podem usar o servidor explicitamente resolvido.
     * `missing`, `ambiguous` e `invalid` resultam em coleção vazia, nunca no
     * primeiro registro disponível.
     */
    /** @return array{serverId:int,serverGroupId:int}|null */
    private static function managementScope(): ?array
    {
        if (!class_exists(IntegrationReadiness::class)) {
            return null;
        }
        try {
            $scope = IntegrationReadiness::scope();
            return is_array($scope) ? $scope : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<int> */
    private static function serviceIdsForServer(int $serverId, int $serverGroupId): array
    {
        if ($serverId <= 0 || $serverGroupId <= 0 || !self::capabilities()['services']) {
            return [];
        }
        try {
            $ids = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.server', $serverId)
                ->where('p.servertype', 'bladecp')
                ->where('p.servergroup', $serverGroupId)
                ->pluck('h.id');
            $out = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[] = $id;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private static function hasTable(string $table): bool
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            return \WHMCS\Database\Capsule::schema()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        try {
            return \WHMCS\Database\Capsule::schema()->getColumnListing($table);
        } catch (\Throwable) {
            return [];
        }
    }
}
