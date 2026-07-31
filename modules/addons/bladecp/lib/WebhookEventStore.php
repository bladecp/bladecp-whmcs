<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Ledger idempotente do receiver.
 *
 * Não armazena corpo, assinatura ou segredo. A tabela anterior de dedupe é
 * preservada somente para compatibilidade operacional; novos eventos usam
 * este ledger, cuja unicidade é por servidor + identificador do evento.
 */
final class WebhookEventStore
{
    public const TABLE = 'mod_bladecp_webhook_event_ledger';
    private const PROCESSING_TTL = 300;

    /** @var array<string,array{id:int,status:string,attempts:int,retryable:bool}> */
    private static array $memory = [];

    /**
     * @return array{state:'claimed'|'processed'|'busy'|'unavailable',id?:int,attempts?:int}
     */
    public static function claim(
        int $serverId,
        string $webhookId,
        string $event,
        string $provisionUuid,
        string $endpointUuid = '',
        ?string $resourceVersion = null
    ): array
    {
        $serverId = max(0, $serverId);
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return ['state' => 'unavailable'];
        }

        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return self::claimMemory($serverId, $webhookId);
        }

        try {
            self::ensureTable();
            return \WHMCS\Database\Capsule::connection()->transaction(
                static function () use ($serverId, $webhookId, $event, $provisionUuid, $endpointUuid, $resourceVersion): array {
                    $table = \WHMCS\Database\Capsule::table(self::TABLE);
                    $row = $table->where('server_id', $serverId)
                        ->where('webhook_id', $webhookId)
                        ->lockForUpdate()
                        ->first();
                    $now = date('Y-m-d H:i:s');
                    if ($row === null) {
                        if (self::isStaleResourceVersion($serverId, $provisionUuid, $resourceVersion)) {
                            $id = self::insertEvent(
                                $serverId,
                                $webhookId,
                                $event,
                                $provisionUuid,
                                $endpointUuid,
                                $resourceVersion,
                                'ignored',
                                0
                            );
                            return ['state' => 'ignored', 'id' => $id, 'attempts' => 1];
                        }
                        $id = (int) \WHMCS\Database\Capsule::table(self::TABLE)->insertGetId([
                            'server_id' => $serverId,
                            'webhook_id' => $webhookId,
                            'event_type' => self::short($event, 100),
                            'provision_uuid' => self::short($provisionUuid, 191),
                            'endpoint_uuid' => self::short($endpointUuid, 191),
                            'resource_version' => $resourceVersion,
                            'status' => 'processing',
                            'attempts' => 1,
                            'retryable' => 1,
                            'received_at' => $now,
                            'processing_at' => $now,
                            'completed_at' => null,
                            'last_error' => null,
                        ]);
                        return ['state' => 'claimed', 'id' => $id, 'attempts' => 1];
                    }

                    $status = (string) ($row->status ?? 'processed');
                    if (
                        $status === 'processed'
                        || $status === 'ignored'
                        || ($status === 'failed' && (int) ($row->retryable ?? 1) !== 1)
                    ) {
                        return ['state' => 'processed', 'id' => (int) $row->id];
                    }
                    if (self::isStaleResourceVersion($serverId, $provisionUuid, $resourceVersion)) {
                        \WHMCS\Database\Capsule::table(self::TABLE)
                            ->where('id', (int) $row->id)
                            ->update([
                                'status' => 'ignored',
                                'retryable' => 0,
                                'completed_at' => $now,
                                'last_error' => 'stale_resource_version',
                            ]);
                        return ['state' => 'ignored', 'id' => (int) $row->id];
                    }
                    if ($status === 'processing' && !self::processingExpired((string) ($row->processing_at ?? ''))) {
                        return ['state' => 'busy', 'id' => (int) $row->id];
                    }

                    $attempts = max(1, (int) ($row->attempts ?? 0)) + 1;
                    \WHMCS\Database\Capsule::table(self::TABLE)
                        ->where('id', (int) $row->id)
                        ->update([
                            'event_type' => self::short($event, 100),
                            'provision_uuid' => self::short($provisionUuid, 191),
                            'endpoint_uuid' => self::short($endpointUuid, 191),
                            'resource_version' => $resourceVersion,
                            'status' => 'processing',
                            'attempts' => $attempts,
                            'retryable' => 1,
                            'processing_at' => $now,
                            'completed_at' => null,
                            'last_error' => null,
                        ]);
                    return ['state' => 'claimed', 'id' => (int) $row->id, 'attempts' => $attempts];
                }
            );
        } catch (\Throwable) {
            // Não é seguro confirmar um evento quando o ledger não está disponível.
            return ['state' => 'unavailable'];
        }
    }

    /** @param array{id?:int} $claim */
    public static function complete(array $claim, string $status): bool
    {
        if (!in_array($status, ['processed', 'ignored'], true)) {
            return false;
        }
        $id = max(0, (int) ($claim['id'] ?? 0));
        if ($id <= 0) {
            return false;
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            foreach (self::$memory as $key => $row) {
                if ($row['id'] === $id) {
                    self::$memory[$key]['status'] = $status;
                    return true;
                }
            }
            return false;
        }
        try {
            \WHMCS\Database\Capsule::table(self::TABLE)->where('id', $id)->update([
                'status' => $status,
                'completed_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{id?:int} $claim */
    public static function fail(array $claim, string $reason, bool $retryable = true): void
    {
        $id = max(0, (int) ($claim['id'] ?? 0));
        if ($id <= 0) {
            return;
        }
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            foreach (self::$memory as $key => $row) {
                if ($row['id'] === $id) {
                    self::$memory[$key]['status'] = 'failed';
                    self::$memory[$key]['retryable'] = $retryable;
                    return;
                }
            }
            return;
        }
        try {
            \WHMCS\Database\Capsule::table(self::TABLE)->where('id', $id)->update([
                'status' => 'failed',
                'completed_at' => date('Y-m-d H:i:s'),
                'last_error' => self::short($reason, 100),
                'retryable' => $retryable ? 1 : 0,
            ]);
        } catch (\Throwable) {
        }
    }

    private static function ensureTable(): void
    {
        self::installSchema();
    }

    /**
     * Cria o ledger quando necessário. Seguro para chamar no hook de
     * ativação e no receiver; não altera a tabela legada de deduplicação.
     */
    public static function installSchema(): void
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, static function ($table): void {
                    $table->increments('id');
                    $table->integer('server_id')->unsigned()->default(0);
                    $table->string('webhook_id', 128);
                    $table->string('event_type', 100)->nullable();
                    $table->string('provision_uuid', 191)->nullable();
                    $table->string('endpoint_uuid', 191)->nullable();
                    $table->string('resource_version', 64)->nullable();
                    $table->string('status', 16)->default('processing');
                    $table->integer('attempts')->unsigned()->default(1);
                    $table->integer('retryable')->unsigned()->default(1);
                    $table->timestamp('received_at')->nullable();
                    $table->timestamp('processing_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->string('last_error', 100)->nullable();
                    $table->unique(['server_id', 'webhook_id'], 'bladecp_webhook_server_event_unique');
                    $table->index(['status', 'received_at'], 'bladecp_webhook_status_received_index');
                });
            }
            self::ensureColumns($schema);
        } catch (\Throwable) {
            // O receiver devolverá 503 ao não conseguir reivindicar o evento.
        }
    }

    /**
     * Metadados recentes para operação. Corpos, assinaturas e segredos não
     * são armazenados nem retornados por este método.
     *
     * @return list<array{at:string,status:string,event:string,provisionUuid:string,attempts:int,retryable:bool}>
     */
    public static function recent(int $limit = 10, int $serverId = 0): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }
        if ($serverId <= 0 && method_exists(WebhookConfig::class, 'managementServerId')) {
            $serverId = (int) WebhookConfig::managementServerId();
        }
        if ($serverId <= 0) {
            return [];
        }
        $limit = max(1, min(30, $limit));
        try {
            if (!\WHMCS\Database\Capsule::schema()->hasTable(self::TABLE)) {
                return [];
            }
            $rows = \WHMCS\Database\Capsule::table(self::TABLE)
                ->where('server_id', $serverId)
                ->select([
                    'received_at', 'status', 'event_type', 'provision_uuid',
                    'attempts', 'retryable',
                ])
                ->orderByDesc('received_at')
                ->limit($limit)
                ->get();
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'at' => trim((string) ($row->received_at ?? '')),
                    'status' => trim((string) ($row->status ?? '')),
                    'event' => trim((string) ($row->event_type ?? '')),
                    'provisionUuid' => trim((string) ($row->provision_uuid ?? '')),
                    'attempts' => max(0, (int) ($row->attempts ?? 0)),
                    'retryable' => (int) ($row->retryable ?? 1) === 1,
                ];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Remove somente eventos terminais antigos. Eventos em processamento não
     * são removidos, para que uma entrega interrompida continue recuperável.
     */
    public static function prune(int $days = 30): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        $days = max(1, min(3650, $days));
        try {
            if (!\WHMCS\Database\Capsule::schema()->hasTable(self::TABLE)) {
                return 0;
            }
            return (int) \WHMCS\Database\Capsule::table(self::TABLE)
                ->whereIn('status', ['processed', 'ignored', 'failed'])
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', date('Y-m-d H:i:s', time() - ($days * 86400)))
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array{state:'claimed'|'processed'|'busy',id:int,attempts?:int} */
    private static function claimMemory(int $serverId, string $webhookId): array
    {
        $key = $serverId . ':' . $webhookId;
        $current = self::$memory[$key] ?? null;
        if ($current !== null) {
            if ($current['status'] === 'processing') {
                return ['state' => 'busy', 'id' => $current['id']];
            }
            if ($current['status'] === 'failed' && $current['retryable']) {
                self::$memory[$key]['status'] = 'processing';
                self::$memory[$key]['attempts']++;
                return [
                    'state' => 'claimed',
                    'id' => $current['id'],
                    'attempts' => self::$memory[$key]['attempts'],
                ];
            }
            return ['state' => 'processed', 'id' => $current['id']];
        }
        $id = (int) sprintf('%u', crc32($key));
        self::$memory[$key] = [
            'id' => $id,
            'status' => 'processing',
            'attempts' => 1,
            'retryable' => true,
        ];
        return ['state' => 'claimed', 'id' => $id, 'attempts' => 1];
    }

    private static function processingExpired(string $value): bool
    {
        $at = strtotime($value);
        return $at === false || $at < (time() - self::PROCESSING_TTL);
    }

    /** @param object $schema */
    private static function ensureColumns(object $schema): void
    {
        if (!$schema->hasColumn(self::TABLE, 'endpoint_uuid')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->string('endpoint_uuid', 191)->nullable();
            });
        }
        if (!$schema->hasColumn(self::TABLE, 'retryable')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->integer('retryable')->unsigned()->default(1);
            });
        }
        if (!$schema->hasColumn(self::TABLE, 'resource_version')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->string('resource_version', 64)->nullable();
            });
        }
    }

    private static function insertEvent(
        int $serverId,
        string $webhookId,
        string $event,
        string $provisionUuid,
        string $endpointUuid,
        ?string $resourceVersion,
        string $status,
        int $retryable
    ): int {
        $now = date('Y-m-d H:i:s');
        return (int) \WHMCS\Database\Capsule::table(self::TABLE)->insertGetId([
            'server_id' => $serverId,
            'webhook_id' => $webhookId,
            'event_type' => self::short($event, 100),
            'provision_uuid' => self::short($provisionUuid, 191),
            'endpoint_uuid' => self::short($endpointUuid, 191),
            'resource_version' => $resourceVersion,
            'status' => $status,
            'attempts' => 1,
            'retryable' => $retryable,
            'received_at' => $now,
            'processing_at' => $now,
            'completed_at' => $status === 'processing' ? null : $now,
            'last_error' => $status === 'ignored' ? 'stale_resource_version' : null,
        ]);
    }

    private static function isStaleResourceVersion(
        int $serverId,
        string $provisionUuid,
        ?string $resourceVersion
    ): bool {
        if ($resourceVersion === null || $provisionUuid === '') {
            return false;
        }
        $rows = \WHMCS\Database\Capsule::table(self::TABLE)
            ->where('server_id', $serverId)
            ->where('provision_uuid', $provisionUuid)
            ->where('status', 'processed')
            ->lockForUpdate()
            ->get();
        foreach ($rows as $row) {
            $known = trim((string) ($row->resource_version ?? ''));
            if ($known !== '' && self::compareVersions($resourceVersion, $known) <= 0) {
                return true;
            }
        }
        return false;
    }

    /** Compara inteiros decimais arbitrariamente grandes, sem extensão GMP. */
    private static function compareVersions(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }
        return $left <=> $right;
    }

    private static function short(string $value, int $limit): string
    {
        return function_exists('mb_substr')
            ? mb_substr(trim($value), 0, $limit)
            : substr(trim($value), 0, $limit);
    }
}
