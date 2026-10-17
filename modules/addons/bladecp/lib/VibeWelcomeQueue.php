<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Durable, opt-in work queue for native Vibe welcome delivery.
 *
 * It never discovers or bulk-scans services. Only a caller that already owns
 * a concrete Vibe service ID can enqueue it. The queue stores no client data,
 * email address, template body or delivery response.
 */
final class VibeWelcomeQueue
{
    public const TABLE = 'mod_bladecp_vibe_welcome_queue';
    private const MAX_LIMIT = 20;
    private const HOLD_SECONDS = 86400;

    /** Adds one Vibe service to the queue without sending any email. */
    public static function enqueue(int $serviceId): bool
    {
        if (self::snapshot($serviceId) === null || !self::installSchema()) {
            return false;
        }
        try {
            return Capsule::connection()->transaction(static function () use ($serviceId): bool {
                $existing = Capsule::table(self::TABLE)->where('service_id', $serviceId)->lockForUpdate()->first();
                if ($existing !== null) {
                    return true;
                }
                $now = self::now();
                Capsule::table(self::TABLE)->insert([
                    'service_id' => $serviceId,
                    'status' => 'queued',
                    'attempts' => 0,
                    'enqueued_at' => $now,
                    'next_attempt_at' => $now,
                    'completed_at' => null,
                    'reason' => null,
                ]);
                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Processes only entries previously inserted through enqueue().
     *
     * @return array{processed:int,delivered:int,retried:int,review:int,skipped:int}
     */
    public static function flush(int $limit = self::MAX_LIMIT): array
    {
        $summary = ['processed' => 0, 'delivered' => 0, 'retried' => 0, 'review' => 0, 'skipped' => 0];
        if (!self::installSchema()) {
            return $summary;
        }
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $now = self::now();
        try {
            $candidates = Capsule::table(self::TABLE)->where('status', 'queued')->orderBy('id')->get();
        } catch (\Throwable) {
            return $summary;
        }
        foreach ($candidates as $candidate) {
            if ($summary['processed'] >= $limit) {
                break;
            }
            if (trim((string) ($candidate->next_attempt_at ?? '')) > $now) {
                continue;
            }
            $claim = self::claim((int) ($candidate->id ?? 0));
            if ($claim === null) {
                $summary['skipped']++;
                continue;
            }
            $summary['processed']++;
            $snapshot = self::snapshot($claim['serviceId']);
            if ($snapshot === null || $snapshot['state'] === 'terminal') {
                self::complete($claim['id'], 'not_eligible', 'service_not_eligible');
                $summary['skipped']++;
                continue;
            }
            if ($snapshot['state'] === 'pending') {
                self::retryOrReview($claim, 'not_yet_active', $summary);
                continue;
            }

            $delivery = VibeWelcomeDelivery::deliver($claim['serviceId']);
            $status = (string) ($delivery['status'] ?? 'unavailable');
            if (in_array($status, ['sent', 'native_sent'], true)) {
                self::complete($claim['id'], 'sent', $status);
                $summary['delivered']++;
                continue;
            }
            if ($status === 'unavailable') {
                self::retryOrReview($claim, 'delivery_unavailable', $summary);
                continue;
            }
            if (in_array($status, ['uncertain', 'needs_review'], true)) {
                self::complete($claim['id'], $status, $status);
                $summary['review']++;
                continue;
            }
            self::complete($claim['id'], 'not_eligible', $status === 'not_eligible' ? 'not_eligible' : 'delivery_not_eligible');
            $summary['skipped']++;
        }
        return $summary;
    }

    /** @return array{serviceId:int,state:'active'|'pending'|'terminal'}|null */
    private static function snapshot(int $serviceId): ?array
    {
        if ($serviceId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $schema = Capsule::schema();
            foreach (['tblhosting', 'tblproducts'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            $productColumns = array_flip($schema->getColumnListing('tblproducts'));
            foreach (['id', 'packageid', 'domainstatus'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }
            foreach (['id', 'servertype', 'configoption1'] as $column) {
                if (!isset($productColumns[$column])) {
                    return null;
                }
            }
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($service === null) {
                return null;
            }
            $product = Capsule::table('tblproducts')->where('id', (int) ($service->packageid ?? 0))->first();
            if ($product === null
                || strtolower(trim((string) ($product->servertype ?? ''))) !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            return match (strtolower(trim((string) ($service->domainstatus ?? '')))) {
                'active' => ['serviceId' => $serviceId, 'state' => 'active'],
                'pending' => ['serviceId' => $serviceId, 'state' => 'pending'],
                default => ['serviceId' => $serviceId, 'state' => 'terminal'],
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{id:int,serviceId:int,enqueuedAt:string,attempts:int}|null */
    private static function claim(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        try {
            return Capsule::connection()->transaction(static function () use ($id): ?array {
                $row = Capsule::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                if ($row === null || (string) ($row->status ?? '') !== 'queued') {
                    return null;
                }
                $attempts = max(0, (int) ($row->attempts ?? 0)) + 1;
                Capsule::table(self::TABLE)->where('id', $id)->update([
                    'status' => 'in_flight',
                    'attempts' => $attempts,
                    'reason' => null,
                ]);
                return [
                    'id' => $id,
                    'serviceId' => (int) ($row->service_id ?? 0),
                    'enqueuedAt' => (string) ($row->enqueued_at ?? ''),
                    'attempts' => $attempts,
                ];
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{id:int,serviceId:int,enqueuedAt:string,attempts:int} $claim @param array{processed:int,delivered:int,retried:int,review:int,skipped:int} $summary */
    private static function retryOrReview(array $claim, string $reason, array &$summary): void
    {
        if (self::expired($claim['enqueuedAt'])) {
            self::complete($claim['id'], 'needs_review', 'queue_hold_expired');
            $summary['review']++;
            return;
        }
        $delay = min(3600, 300 * max(1, $claim['attempts']));
        try {
            Capsule::table(self::TABLE)->where('id', $claim['id'])->update([
                'status' => 'queued',
                'next_attempt_at' => date('Y-m-d H:i:s', time() + $delay),
                'reason' => $reason,
            ]);
            $summary['retried']++;
        } catch (\Throwable) {
            // The in-flight claim is intentionally not released when its next
            // state cannot be durably recorded. Human review is safer than a
            // duplicate welcome message after a process interruption.
            $summary['review']++;
        }
    }

    private static function complete(int $id, string $status, string $reason): void
    {
        try {
            Capsule::table(self::TABLE)->where('id', $id)->update([
                'status' => $status,
                'completed_at' => self::now(),
                'reason' => $reason,
            ]);
        } catch (\Throwable) {
        }
    }

    private static function expired(string $enqueuedAt): bool
    {
        $at = strtotime($enqueuedAt);
        return $at === false || $at + self::HOLD_SECONDS <= time();
    }

    private static function installSchema(): bool
    {
        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::TABLE)) {
                $schema->create(self::TABLE, static function ($table): void {
                    $table->increments('id');
                    $table->integer('service_id')->unsigned();
                    $table->string('status', 16);
                    $table->integer('attempts')->unsigned()->default(0);
                    $table->timestamp('enqueued_at')->nullable();
                    $table->timestamp('next_attempt_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->string('reason', 64)->nullable();
                    $table->unique(['service_id'], 'bladecp_vibe_welcome_queue_service_unique');
                    $table->index(['status', 'next_attempt_at'], 'bladecp_vibe_welcome_queue_due_index');
                });
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
