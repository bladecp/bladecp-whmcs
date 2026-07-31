<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Estado local e auditável da disponibilidade do catálogo.
 *
 * A API informa a disponibilidade observada. A decisão efetiva fica neste
 * repositório para que uma resposta posterior positiva não reabra uma venda
 * que o administrador ou uma guarda anterior já fechou. Produtos e serviços
 * WHMCS nunca são apagados por este processo.
 */
final class CatalogStateRepository
{
    public const TABLE = 'mod_bladecp_catalog_product_state';
    public const IMPORT_LOCK_TABLE = 'mod_bladecp_catalog_import_lock';

    private static function db(): mixed
    {
        return class_exists(\WHMCS\Database\Capsule::class)
            ? \WHMCS\Database\Capsule::class
            : null;
    }

    private static function schema(): mixed
    {
        $db = self::db();
        return $db === null ? null : $db::schema();
    }

    /** Cria a tabela sem tocar nos produtos, serviços ou tabelas nativas. */
    public static function installSchema(): void
    {
        $schema = self::schema();
        if ($schema === null) {
            return;
        }
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->integer('product_id')->unique();
                $table->string('plan_id')->index();
                $table->integer('server_id')->nullable()->index();
                $table->integer('server_group_id')->nullable()->index();
                $table->integer('source_schema_version')->nullable();
                $table->text('observed_availability')->nullable();
                $table->integer('observed_sales_eligible')->default(0);
                $table->string('observed_readiness')->nullable();
                $table->text('observed_blockers')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->integer('effective_sales_eligible')->default(0);
                $table->string('effective_reason')->nullable();
                $table->text('effective_blockers')->nullable();
                $table->timestamp('effective_decision_at')->nullable();
                $table->integer('reconciliation_pending')->default(0);
                $table->integer('hidden_by_bladecp')->default(0);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
        if (!$schema->hasColumn(self::TABLE, 'reconciliation_pending')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->integer('reconciliation_pending')->default(0);
            });
        }
        if (!$schema->hasColumn(self::TABLE, 'server_id')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->integer('server_id')->nullable()->index();
            });
        }
        if (!$schema->hasColumn(self::TABLE, 'server_group_id')) {
            $schema->table(self::TABLE, static function ($table): void {
                $table->integer('server_group_id')->nullable()->index();
            });
        }
        if (!$schema->hasTable(self::IMPORT_LOCK_TABLE)) {
            $schema->create(self::IMPORT_LOCK_TABLE, static function ($table): void {
                $table->string('lock_key', 191)->primary();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    /**
     * Serializa operações de importação por recursos estáveis do catálogo.
     *
     * O lock fica no banco para funcionar entre requisições PHP diferentes,
     * não apenas dentro do mesmo processo. As chaves são ordenadas antes da
     * aquisição para evitar inversão de locks entre dois imports concorrentes.
     *
     * @param list<string> $keys
     */
    public static function withImportLocks(array $keys, callable $callback): mixed
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys
        ), static fn (string $key): bool => $key !== '')));
        sort($keys, SORT_STRING);
        if ($keys === []) {
            return null;
        }
        self::installSchema();
        $db = self::db();
        if ($db === null || !self::isInstalled()) {
            return null;
        }
        return $db::connection()->transaction(static function () use ($db, $keys, $callback): mixed {
            foreach ($keys as $key) {
                self::acquireImportLock($db, $key);
            }
            return $callback();
        }, 3);
    }

    private static function acquireImportLock(mixed $db, string $key): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $db::table(self::IMPORT_LOCK_TABLE)->insert([
                'lock_key' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable) {
            // A chave já existe em outra requisição ou foi criada antes. O
            // lockForUpdate abaixo é a barreira real de serialização.
        }
        $row = $db::table(self::IMPORT_LOCK_TABLE)
            ->where('lock_key', $key)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw new \RuntimeException('Não foi possível adquirir o lock de importação do catálogo.');
        }
        $db::table(self::IMPORT_LOCK_TABLE)->where('lock_key', $key)->update([
            'updated_at' => $now,
        ]);
    }

    public static function isInstalled(): bool
    {
        try {
            $schema = self::schema();
            return $schema !== null && $schema->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            $db = self::db();
            if ($db === null || !self::isInstalled()) {
                return null;
            }
            $row = $db::table(self::TABLE)->where('product_id', $productId)->first();
            return $row === null ? null : self::hydrate($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Grava a observação remota e mantém o teto local: uma resposta positiva
     * posterior nunca altera `effective_sales_eligible` de falso para verdadeiro.
     * Nessa situação a observação fresca fica explícita como pendente de
     * reconciliação administrativa.
     *
     * @param array<string,mixed> $availability
     * @return array<string,mixed>|null
     */
    public static function observe(
        int $productId,
        string $planId,
        array $availability,
        ?int $serverId = null,
        ?int $serverGroupId = null
    ): ?array {
        if ($productId <= 0 || trim($planId) === '') {
            return null;
        }
        try {
            self::installSchema();
            $db = self::db();
            if ($db === null || !self::isInstalled()) {
                return null;
            }
            $normalized = self::normalizeAvailability($availability);
            $scope = self::scopeForProduct($productId, $serverId, $serverGroupId);
            $now = date('Y-m-d H:i:s');
            $observedEligible = $normalized['salesEligible'] ? 1 : 0;
            return $db::connection()->transaction(static function () use (
                $db,
                $productId,
                $planId,
                $normalized,
                $scope,
                $now,
                $observedEligible
            ): ?array {
                $row = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
                $existing = $row === null ? null : self::hydrate($row);
                if ($existing !== null && !self::sameScope($existing, $scope)) {
                    return null;
                }
                $effectiveEligible = $existing === null
                    ? $observedEligible
                    : ($existing['effectiveSalesEligible'] && $observedEligible === 1 ? 1 : 0);
                $pendingReconciliation = $existing !== null
                    && !$existing['effectiveSalesEligible']
                    && $observedEligible === 1;
                $reason = $effectiveEligible === 1
                    ? ''
                    : ($pendingReconciliation ? 'reconciliation_pending' : self::reason($normalized));
                $blockers = $pendingReconciliation
                    ? [[
                        'code' => 'reconciliation_pending',
                        'retryable' => false,
                        'action' => 'review_catalog',
                    ]]
                    : $normalized['blockers'];
                $payload = [
                    'plan_id' => trim($planId),
                    'server_id' => $scope['serverId'],
                    'server_group_id' => $scope['serverGroupId'],
                    'source_schema_version' => $normalized['schemaVersion'],
                    'observed_availability' => self::json($normalized['raw']),
                    'observed_sales_eligible' => $observedEligible,
                    'observed_readiness' => $normalized['readiness'],
                    'observed_blockers' => self::json($normalized['blockers']),
                    'observed_at' => $normalized['checkedAt'] ?? $now,
                    'valid_until' => $normalized['validUntil'],
                    'effective_sales_eligible' => $effectiveEligible,
                    'effective_reason' => $reason,
                    'effective_blockers' => self::json($blockers),
                    'effective_decision_at' => $now,
                    'reconciliation_pending' => $pendingReconciliation ? 1 : 0,
                    'updated_at' => $now,
                ];
                if ($existing === null) {
                    $payload['product_id'] = $productId;
                    $payload['hidden_by_bladecp'] = 0;
                    $payload['created_at'] = $now;
                    $db::table(self::TABLE)->insert($payload);
                } else {
                    $db::table(self::TABLE)->where('product_id', $productId)->update($payload);
                }
                $persisted = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->first();
                return $persisted === null ? null : self::hydrate($persisted);
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Libera a decisão comercial somente por ação explícita após uma
     * observação atual, elegível e ainda válida. Não altera `hidden` do
     * produto: a reexposição da vitrine continua uma decisão separada da UI.
     *
     * @return array<string,mixed>|null
     */
    public static function approveFreshEligibleSale(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        try {
            self::installSchema();
            $db = self::db();
            if ($db === null || !self::isInstalled()) {
                return null;
            }
            return $db::connection()->transaction(static function () use ($db, $productId): ?array {
                $row = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
                if ($row === null) {
                    return null;
                }
                $state = self::hydrate($row);
                if (!self::isFreshEligible($state)) {
                    return null;
                }
                $now = date('Y-m-d H:i:s');
                $db::table(self::TABLE)->where('product_id', $productId)->update([
                    'effective_sales_eligible' => 1,
                    'effective_reason' => '',
                    'effective_blockers' => '[]',
                    'effective_decision_at' => $now,
                    'reconciliation_pending' => 0,
                    'updated_at' => $now,
                ]);
                $persisted = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->first();
                return $persisted === null ? null : self::hydrate($persisted);
            }, 3);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Fecha a venda localmente, sem caminho implícito de reativação. */
    public static function closeSale(
        int $productId,
        string $reason,
        array $blockers = []
    ): ?array {
        if ($productId <= 0) {
            return null;
        }
        try {
            self::installSchema();
            $db = self::db();
            if ($db === null || !self::isInstalled()) {
                return null;
            }
            return $db::connection()->transaction(static function () use ($db, $productId, $reason, $blockers): ?array {
                $row = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
                $now = date('Y-m-d H:i:s');
                $pendingReconciliation = trim($reason) === 'reconciliation_pending';
                $payload = [
                    'effective_sales_eligible' => 0,
                    'effective_reason' => trim($reason) !== '' ? trim($reason) : 'not-eligible',
                    'effective_blockers' => self::json(self::blockers($blockers)),
                    'effective_decision_at' => $now,
                    'reconciliation_pending' => $pendingReconciliation ? 1 : 0,
                    'updated_at' => $now,
                ];
                if ($row === null) {
                    $payload += [
                        'product_id' => $productId,
                        'plan_id' => '',
                        'observed_sales_eligible' => 0,
                        'created_at' => $now,
                        'hidden_by_bladecp' => 0,
                    ];
                    $db::table(self::TABLE)->insert($payload);
                } else {
                    $db::table(self::TABLE)->where('product_id', $productId)->update($payload);
                }
                $persisted = $db::table(self::TABLE)
                    ->where('product_id', $productId)
                    ->first();
                return $persisted === null ? null : self::hydrate($persisted);
            }, 3);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Marca que a ocultação do produto foi aplicada por esta integração. */
    public static function markHiddenByModule(int $productId): void
    {
        try {
            $db = self::db();
            if ($db === null || !self::isInstalled() || $productId <= 0) {
                return;
            }
            $db::table(self::TABLE)->where('product_id', $productId)->update([
                'hidden_by_bladecp' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * Registra que uma reexposição explícita retirou a ocultação aplicada por
     * esta integração. Não altera `tblproducts.hidden` diretamente.
     */
    public static function markVisibleByModule(int $productId): void
    {
        try {
            $db = self::db();
            if ($db === null || !self::isInstalled() || $productId <= 0) {
                return;
            }
            $db::table(self::TABLE)->where('product_id', $productId)->update([
                'hidden_by_bladecp' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(?int $serverId = null, ?int $serverGroupId = null): array
    {
        try {
            $db = self::db();
            if ($db === null || !self::isInstalled()) {
                return [];
            }
            $out = [];
            $query = $db::table(self::TABLE)->orderBy('product_id');
            if ($serverId !== null && $serverId > 0 && $serverGroupId !== null && $serverGroupId > 0) {
                $query->where('server_id', $serverId)->where('server_group_id', $serverGroupId);
            }
            foreach ($query->get() as $row) {
                $out[] = self::hydrate($row);
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{total:int,eligible:int,blocked:int,unknown:int,stale:int,lastObservedAt:?string} */
    public static function summary(?int $serverId = null, ?int $serverGroupId = null): array
    {
        $out = ['total' => 0, 'eligible' => 0, 'blocked' => 0, 'unknown' => 0, 'stale' => 0, 'lastObservedAt' => null];
        foreach (self::all($serverId, $serverGroupId) as $state) {
            $out['total']++;
            if ($state['effectiveSalesEligible']) {
                $out['eligible']++;
            } else {
                $out['blocked']++;
            }
            if ($state['observedReadiness'] === 'unknown') {
                $out['unknown']++;
            }
            if (self::isExpired($state['validUntil'])) {
                $out['stale']++;
            }
            $observed = $state['observedAt'];
            if ($observed !== null && ($out['lastObservedAt'] === null || $observed > $out['lastObservedAt'])) {
                $out['lastObservedAt'] = $observed;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $availability @return array<string,mixed> */
    private static function normalizeAvailability(array $availability): array
    {
        $blockers = self::blockers(is_array($availability['blockers'] ?? null) ? $availability['blockers'] : []);
        $checkedAt = self::timestamp($availability['checkedAt'] ?? $availability['checked_at'] ?? null);
        $validUntil = self::timestamp($availability['validUntil'] ?? $availability['valid_until'] ?? null);
        $readiness = trim((string) ($availability['readiness'] ?? 'unknown'));
        $eligible = ($availability['salesEligible'] ?? $availability['sales_eligible'] ?? false) === true;
        if ($readiness === '' || $checkedAt === null || $validUntil === null || self::isExpired($validUntil)) {
            $readiness = 'unknown';
            $eligible = false;
            if ($blockers === []) {
                $blockers[] = ['code' => $validUntil === null ? 'availability_unknown' : 'availability_stale', 'retryable' => true, 'action' => 'refresh_catalog'];
            }
        }
        return [
            'schemaVersion' => max(0, (int) ($availability['schemaVersion'] ?? $availability['schema_version'] ?? 0)),
            'salesEligible' => $eligible,
            'readiness' => $readiness,
            'blockers' => $blockers,
            'checkedAt' => $checkedAt,
            'validUntil' => $validUntil,
            'raw' => $availability,
        ];
    }

    /** @param array<int,mixed> $blockers @return array<int,array<string,mixed>> */
    private static function blockers(array $blockers): array
    {
        $out = [];
        foreach ($blockers as $blocker) {
            if (!is_array($blocker)) {
                continue;
            }
            $code = trim((string) ($blocker['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code' => $code,
                'retryable' => ($blocker['retryable'] ?? false) === true,
                'action' => trim((string) ($blocker['action'] ?? '')),
            ];
        }
        return $out;
    }

    private static function reason(array $normalized): string
    {
        if ($normalized['readiness'] === 'unknown') {
            return 'availability-unknown';
        }
        return (string) (($normalized['blockers'][0]['code'] ?? '') ?: 'not-eligible');
    }

    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isExpired(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        try {
            return (new \DateTimeImmutable($value)) <= new \DateTimeImmutable('now');
        } catch (\Throwable) {
            return true;
        }
    }

    private static function json(mixed $value): string
    {
        try {
            return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return '[]';
        }
    }

    /** @return array<string,mixed> */
    private static function hydrate(object $row): array
    {
        return [
            'productId' => (int) ($row->product_id ?? 0),
            'planId' => (string) ($row->plan_id ?? ''),
            'serverId' => self::positiveInt($row->server_id ?? null),
            'serverGroupId' => self::positiveInt($row->server_group_id ?? null),
            'sourceSchemaVersion' => (int) ($row->source_schema_version ?? 0),
            'observedAvailability' => self::decode($row->observed_availability ?? ''),
            'observedSalesEligible' => (int) ($row->observed_sales_eligible ?? 0) === 1,
            'observedReadiness' => (string) ($row->observed_readiness ?? 'unknown'),
            'observedBlockers' => self::decode($row->observed_blockers ?? ''),
            'observedAt' => self::timestamp($row->observed_at ?? null),
            'validUntil' => self::timestamp($row->valid_until ?? null),
            'effectiveSalesEligible' => (int) ($row->effective_sales_eligible ?? 0) === 1,
            'effectiveReason' => (string) ($row->effective_reason ?? ''),
            'effectiveBlockers' => self::decode($row->effective_blockers ?? ''),
            'effectiveDecisionAt' => self::timestamp($row->effective_decision_at ?? null),
            'reconciliationPending' => (int) ($row->reconciliation_pending ?? 0) === 1,
            'hiddenByBladecp' => (int) ($row->hidden_by_bladecp ?? 0) === 1,
        ];
    }

    /** @param array<string,mixed> $state */
    private static function isFreshEligible(array $state): bool
    {
        return ($state['observedSalesEligible'] ?? false) === true
            && ($state['observedReadiness'] ?? '') !== 'unknown'
            && !self::isExpired(is_string($state['validUntil'] ?? null) ? $state['validUntil'] : null)
            && is_string($state['observedAt'] ?? null)
            && $state['observedAt'] !== '';
    }

    /**
     * @return array{serverId:?int,serverGroupId:?int}
     */
    private static function scopeForProduct(
        int $productId,
        ?int $serverId,
        ?int $serverGroupId
    ): array {
        $scope = [
            'serverId' => $serverId !== null && $serverId > 0 ? $serverId : null,
            'serverGroupId' => $serverGroupId !== null && $serverGroupId > 0 ? $serverGroupId : null,
        ];
        if ($scope['serverId'] !== null && $scope['serverGroupId'] !== null) {
            return $scope;
        }
        try {
            $db = self::db();
            if ($db === null) {
                return $scope;
            }
            $product = $db::table('tblproducts')->where('id', $productId)
                ->where('servertype', 'bladecp')->first(['servergroup']);
            $groupId = (int) ($product->servergroup ?? 0);
            if ($groupId <= 0) {
                return $scope;
            }
            $ids = [];
            foreach ($db::table('tblservergroupsrel')->where('groupid', $groupId)->get(['serverid']) as $rel) {
                $id = (int) ($rel->serverid ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
            if (count($ids) !== 1) {
                return $scope;
            }
            $scope['serverGroupId'] = $groupId;
            $scope['serverId'] = (int) array_key_first($ids);
            return $scope;
        } catch (\Throwable) {
            return $scope;
        }
    }

    /** @param array<string,mixed> $state @param array{serverId:?int,serverGroupId:?int} $scope */
    private static function sameScope(array $state, array $scope): bool
    {
        $stateServerId = self::positiveInt($state['serverId'] ?? null);
        $stateGroupId = self::positiveInt($state['serverGroupId'] ?? null);
        if ($stateServerId === null && $stateGroupId === null) {
            return true;
        }
        return $stateServerId === $scope['serverId'] && $stateGroupId === $scope['serverGroupId'];
    }

    private static function positiveInt(mixed $value): ?int
    {
        $value = is_numeric($value) ? (int) $value : 0;
        return $value > 0 ? $value : null;
    }

    /** @return array<mixed> */
    private static function decode(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
