<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Local state of migrations that must be billed by this installation.
 *
 * It keeps what an administrator needs to decide a review without asking the
 * platform again. It is never the billing authority: the native service and
 * its admin-only correlation fields are.
 */
final class BillingStore
{
    public const TABLE = 'mod_bladecp_migration_billing';
    public const REVIEW = 'review';
    public const CLAIM = 'claim_required';
    public const LINKED = 'linked';
    public const CLOSED = 'closed';

    public static function installSchema(): void
    {
        if (!class_exists(Capsule::class)) {
            return;
        }
        try {
            $schema = Capsule::schema();
            if ($schema->hasTable(self::TABLE)) {
                self::ensureColumns($schema);
                return;
            }
            $schema->create(self::TABLE, static function ($table): void {
                $table->increments('id');
                $table->integer('server_id')->unsigned()->default(0);
                $table->string('request_uuid', 36);
                $table->string('state', 20)->default(self::REVIEW);
                $table->string('reason', 40)->nullable();
                $table->string('domain', 253)->nullable();
                $table->string('account', 64)->nullable();
                $table->string('source_server', 253)->nullable();
                $table->string('source_owner', 64)->nullable();
                $table->string('initiated_by', 16)->nullable();
                $table->integer('plan_id')->unsigned()->default(0);
                $table->integer('location_id')->unsigned()->default(0);
                $table->string('mode', 20)->nullable();
                $table->integer('client_id')->unsigned()->default(0);
                $table->integer('service_id')->unsigned()->default(0);
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->string('bridge_decision', 120)->nullable();
                $table->string('bridge_phase', 32)->nullable();
                $table->string('bridge_state', 32)->nullable();
                $table->timestamp('bridge_at')->nullable();
                $table->unique(['server_id', 'request_uuid'], 'bladecp_migration_billing_request_unique');
                $table->index(['server_id', 'state'], 'bladecp_migration_billing_state_index');
            });
        } catch (\Throwable) {
            // The importer retries on its next run; nothing is billed without it.
        }
    }

    /** Additive columns of later releases, for tables created before them. */
    private static function ensureColumns(object $schema): void
    {
        $missing = [];
        foreach (['bridge_decision' => 120, 'bridge_phase' => 32, 'bridge_state' => 32] as $column => $length) {
            if (!$schema->hasColumn(self::TABLE, $column)) {
                $missing[$column] = $length;
            }
        }
        $at = !$schema->hasColumn(self::TABLE, 'bridge_at');
        if ($missing === [] && !$at) {
            return;
        }
        $schema->table(self::TABLE, static function ($table) use ($missing, $at): void {
            foreach ($missing as $column => $length) {
                $table->string($column, $length)->nullable();
            }
            if ($at) {
                $table->timestamp('bridge_at')->nullable();
            }
        });
    }

    /**
     * Records the latest commercial bridge decision of a linked migration.
     * A missing or stale local row is repaired: the platform reports the link
     * and the admin-only service fields prove it. Returns whether the
     * decision changed, so it is logged once instead of every minute.
     */
    public static function recordBridge(int $serverId, string $requestUuid, int $serviceId, int $clientId, string $decision, string $phase, string $state): bool
    {
        $requestUuid = strtolower(trim($requestUuid));
        if ($serverId <= 0 || $requestUuid === '') {
            return false;
        }
        self::installSchema();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'state' => self::LINKED, 'client_id' => max(0, $clientId), 'service_id' => max(0, $serviceId),
            'bridge_decision' => substr($decision, 0, 120), 'bridge_phase' => substr($phase, 0, 32),
            'bridge_state' => substr($state, 0, 32), 'bridge_at' => $now,
        ];
        try {
            $existing = Capsule::table(self::TABLE)->where('server_id', $serverId)->where('request_uuid', $requestUuid)->first();
            if ($existing === null) {
                Capsule::table(self::TABLE)->insert(['server_id' => $serverId, 'request_uuid' => $requestUuid, 'first_seen_at' => $now, 'updated_at' => $now] + $values);
                return true;
            }
            $changed = (string) ($existing->bridge_decision ?? '') !== $values['bridge_decision'];
            Capsule::table(self::TABLE)->where('id', (int) $existing->id)->update($values + ($changed ? ['updated_at' => $now] : []));
            return $changed;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> linked migrations of this server, latest bridge activity first */
    public static function linked(int $serverId, int $limit = 50): array
    {
        if ($serverId <= 0 || !self::available()) {
            return [];
        }
        try {
            $rows = [];
            foreach (Capsule::table(self::TABLE)->where('server_id', $serverId)->where('state', self::LINKED)->get() as $row) {
                $rows[] = self::row($row) + [
                    'bridgeDecision' => (string) ($row->bridge_decision ?? ''),
                    'bridgePhase' => (string) ($row->bridge_phase ?? ''),
                    'bridgeState' => (string) ($row->bridge_state ?? ''),
                    'bridgeAt' => (string) ($row->bridge_at ?? ''),
                ];
            }
            usort($rows, static fn (array $a, array $b): int => strcmp($b['bridgeAt'], $a['bridgeAt']) ?: $b['id'] <=> $a['id']);
            return array_slice($rows, 0, max(1, $limit));
        } catch (\Throwable) {
            return [];
        }
    }

    public static function available(): bool
    {
        try {
            return class_exists(Capsule::class) && Capsule::schema()->hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Records the latest decision for a request. A linked or closed request
     * is final and is never reopened by a later queue read.
     *
     * @param array<string,mixed> $item normalized queue item
     */
    public static function record(int $serverId, array $item, string $state, ?string $reason = null, int $clientId = 0, int $serviceId = 0): bool
    {
        $requestUuid = strtolower(trim((string) ($item['requestUuid'] ?? '')));
        if ($serverId <= 0 || $requestUuid === '' || !in_array($state, [self::REVIEW, self::CLAIM, self::LINKED, self::CLOSED], true)) {
            return false;
        }
        self::installSchema();
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'state' => $state,
            'reason' => $reason === null ? null : substr($reason, 0, 40),
            'domain' => self::text($item['domain'] ?? null, 253),
            'account' => self::text($item['username'] ?? null, 64),
            'source_server' => self::text($item['serverHostname'] ?? null, 253),
            'source_owner' => self::text($item['owner'] ?? null, 64),
            'initiated_by' => self::text($item['initiatedBy'] ?? null, 16),
            'plan_id' => max(0, (int) ($item['planId'] ?? 0)),
            'location_id' => max(0, (int) ($item['locationId'] ?? 0)),
            'mode' => self::text($item['mode'] ?? null, 20),
            'client_id' => max(0, $clientId),
            'service_id' => max(0, $serviceId),
            'updated_at' => $now,
        ];
        try {
            $query = Capsule::table(self::TABLE)->where('server_id', $serverId)->where('request_uuid', $requestUuid);
            $existing = $query->first();
            if ($existing === null) {
                Capsule::table(self::TABLE)->insert(['server_id' => $serverId, 'request_uuid' => $requestUuid, 'first_seen_at' => $now] + $values);
                return true;
            }
            $current = (string) ($existing->state ?? '');
            $reopens = $current === self::CLOSED && (string) ($existing->reason ?? '') === 'left_queue';
            if (in_array($current, [self::LINKED, self::CLOSED], true) && $current !== $state && !$reopens) {
                return false;
            }
            Capsule::table(self::TABLE)->where('id', (int) $existing->id)->update($values);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Closes the reviews of this server whose request is no longer waiting
     * for a billing link.
     *
     * @param list<string> $queued request UUIDs read in a complete queue pass
     */
    public static function closeReviewsNotIn(int $serverId, array $queued): int
    {
        if ($serverId <= 0 || !self::available()) {
            return 0;
        }
        $queued = array_flip(array_map('strtolower', $queued));
        $closed = 0;
        try {
            foreach (Capsule::table(self::TABLE)->where('server_id', $serverId)->where('state', self::REVIEW)->get() as $row) {
                if (isset($queued[strtolower((string) ($row->request_uuid ?? ''))])) {
                    continue;
                }
                $closed += Capsule::table(self::TABLE)->where('id', (int) $row->id)->where('state', self::REVIEW)
                    ->update(['state' => self::CLOSED, 'reason' => 'left_queue', 'updated_at' => gmdate('Y-m-d H:i:s')]);
            }
        } catch (\Throwable) {
            return $closed;
        }
        return $closed;
    }

    /** Whether this server has a migration linked by this installation, so its commercial work is worth reading. */
    public static function hasLinked(int $serverId): bool
    {
        if ($serverId <= 0 || !self::available()) {
            return false;
        }
        try {
            return Capsule::table(self::TABLE)->where('server_id', $serverId)->where('state', self::LINKED)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public static function find(int $serverId, int $id): ?array
    {
        if ($serverId <= 0 || $id <= 0 || !self::available()) {
            return null;
        }
        try {
            $row = Capsule::table(self::TABLE)->where('id', $id)->where('server_id', $serverId)->first();
            return $row === null ? null : self::row($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    public static function forRequest(int $serverId, string $requestUuid): ?array
    {
        if ($serverId <= 0 || $requestUuid === '' || !self::available()) {
            return null;
        }
        try {
            $row = Capsule::table(self::TABLE)->where('server_id', $serverId)->where('request_uuid', strtolower($requestUuid))->first();
            return $row === null ? null : self::row($row);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string,mixed>> */
    public static function open(int $serverId, int $limit = 100): array
    {
        if ($serverId <= 0 || !self::available()) {
            return [];
        }
        try {
            $rows = [];
            foreach (Capsule::table(self::TABLE)->where('server_id', $serverId)->where('state', self::REVIEW)->orderBy('id')->get() as $row) {
                $rows[] = self::row($row);
            }
            usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['updatedAt'], (string) $a['updatedAt']) ?: $b['id'] <=> $a['id']);
            return array_slice($rows, 0, max(1, $limit));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Queue item rebuilt from a stored review, for the administrator's decision. */
    public static function item(array $row): array
    {
        return [
            'requestUuid' => (string) $row['requestUuid'],
            'phase' => '',
            // Only cPanel Migrator sources reach the billing queue.
            'kind' => 'cpanel',
            'domain' => $row['domain'],
            'username' => $row['account'],
            'serverHostname' => $row['sourceServer'],
            'owner' => $row['sourceOwner'],
            'initiatedBy' => $row['initiatedBy'],
            'planId' => (int) $row['planId'],
            'locationId' => (int) $row['locationId'],
            'mode' => (string) $row['mode'],
        ];
    }

    /** @return array<string,mixed> */
    private static function row(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'serverId' => (int) ($row->server_id ?? 0),
            'requestUuid' => (string) ($row->request_uuid ?? ''),
            'state' => (string) ($row->state ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'domain' => self::text($row->domain ?? null, 253),
            'account' => self::text($row->account ?? null, 64),
            'sourceServer' => self::text($row->source_server ?? null, 253),
            'sourceOwner' => self::text($row->source_owner ?? null, 64),
            'initiatedBy' => self::text($row->initiated_by ?? null, 16),
            'planId' => (int) ($row->plan_id ?? 0),
            'locationId' => (int) ($row->location_id ?? 0),
            'mode' => (string) ($row->mode ?? ''),
            'clientId' => (int) ($row->client_id ?? 0),
            'serviceId' => (int) ($row->service_id ?? 0),
            'firstSeenAt' => (string) ($row->first_seen_at ?? ''),
            'updatedAt' => (string) ($row->updated_at ?? ''),
        ];
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : substr($value, 0, $max);
    }
}
