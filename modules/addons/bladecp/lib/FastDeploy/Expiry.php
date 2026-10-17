<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Module\Addon\Bladecp\ClientExperienceSettings;
use WHMCS\Module\Addon\Bladecp\FastDeployGateway;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Reconciles only API-issued commercial work with the native service.
 *
 * There is no local Fast Deploy ledger. The API decides deadline state on its
 * own clock, while this worker validates the service binding again before any
 * WHMCS lifecycle action and reports a successful action back to the API.
 */
final class Expiry
{
    /**
     * @param list<int>|null $onlyServerIds limit the pass to these servers
     * @param bool $linkedOnly reconcile only migrations linked to this installation
     * @param float|null $deadline microtime after which no new item starts
     * @return array{migrations:int,skipped:int}
     */
    public static function run(?array $onlyServerIds = null, bool $linkedOnly = false, ?float $deadline = null): array
    {
        $summary = ['migrations' => 0, 'skipped' => 0];
        if (!method_exists(FastDeployGateway::class, 'forServer')) {
            return $summary;
        }
        $serverIds = $linkedOnly ? [] : ClientExperienceSettings::fastDeployServerIds();
        // Linked cPanel Migrator migrations are billed with or without the
        // client area Fast Deploy, under the policy frozen in each migration.
        $billing = class_exists(BillingSettings::class) && method_exists(FastDeployGateway::class, 'forBilling');
        if ($billing) {
            // A server that bills migrations, or already holds a linked one,
            // has that work to read. A local row is not required: a link
            // whose response was lost is still reconciled and recorded.
            $linked = array_values(array_filter(BillingSettings::bladecpServerIds(),
                static fn (int $id): bool => BillingSettings::enabled($id) || BillingStore::hasLinked($id)));
            $serverIds = array_values(array_unique(array_merge($serverIds, $linked)));
        }
        if ($onlyServerIds !== null) {
            $serverIds = array_values(array_intersect($serverIds, $onlyServerIds));
        }
        foreach ($serverIds as $serverId) {
            if (!is_int($serverId) && !(is_string($serverId) && ctype_digit($serverId))) {
                continue;
            }
            $serverId = (int) $serverId;
            if ($serverId <= 0) {
                continue;
            }
            try {
                $gateway = $linkedOnly
                    ? ($billing ? FastDeployGateway::forBilling($serverId, true) : null)
                    : (FastDeployGateway::forServer($serverId) ?? ($billing ? FastDeployGateway::forBilling($serverId) : null));
                if ($gateway === null || !method_exists($gateway, 'commercialWork')) {
                    continue;
                }
                $work = $gateway->commercialWork();
                if (!is_array($work)) {
                    continue;
                }
                $complete = true;
                foreach ($work as $item) {
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        $complete = false;
                        break;
                    }
                    if (is_array($item)) {
                        $summary['migrations']++;
                        if (str_starts_with(self::applyAndRecord($serverId, $item), 'skipped')) {
                            $summary['skipped']++;
                        }
                    }
                }
                // Failed requests remain eligible and are revisited on the next
                // full cursor cycle; one failure must not starve later pages.
                // A page cut by the time budget is read again next time.
                if ($complete) {
                    $gateway->acknowledgeCommercialWork();
                }
            } catch (\Throwable $error) {
                // A failed partner/server is isolated from the remaining cron work.
                self::log('BladeCP commercial bridge: server_id=' . $serverId . '; decision=skipped: server unavailable ('
                    . (new \ReflectionClass($error))->getShortName() . ');');
            }
        }
        return $summary;
    }

    /**
     * Applies one work item and records the decision. A linked migration
     * keeps its latest decision locally and logs it when it changes, so a
     * skipped migration is never hidden behind a task without errors.
     *
     * @param array<string,mixed> $item
     */
    private static function applyAndRecord(int $serverId, array $item): string
    {
        $linked = class_exists(BillingLink::class) && BillingLink::isLinked($item);
        $serviceId = self::positive($item['commercial']['externalServiceId'] ?? null);
        $requestUuid = strtolower(trim((string) ($item['requestUuid'] ?? $item['request_uuid'] ?? '')));
        $before = $linked && $serviceId !== null ? ServiceFields::service($serviceId) : null;
        $outcome = ['decision' => 'skipped: invalid work item', 'phase' => strtolower(trim((string) ($item['migration']['phase'] ?? ''))),
            'state' => strtolower(trim((string) ($item['commercial']['state'] ?? '')))];
        try {
            $outcome = self::apply($serverId, $item) + $outcome;
        } catch (\Throwable $error) {
            // One malformed or transient item must not starve the remaining
            // bounded work page, and its cause is never silent.
            $outcome['decision'] = 'skipped: error ' . (new \ReflectionClass($error))->getShortName();
            if (!$linked) {
                self::log('BladeCP commercial bridge: request=' . $requestUuid . '; decision=' . $outcome['decision'] . ';');
            }
        }
        if (!$linked || $serviceId === null) {
            return $outcome['decision'];
        }
        $after = ServiceFields::service($serviceId);
        if ($before !== null && $after !== null && !str_starts_with($outcome['decision'], 'skipped')) {
            if ($before['status'] === 'pending' && $after['status'] === 'active') {
                $outcome['decision'] = 'service activated';
            } elseif (($before['fields'][ServiceFields::INVOICE_ID] ?? '') === '' && ($after['fields'][ServiceFields::INVOICE_ID] ?? '') !== '') {
                $outcome['decision'] = 'invoice issued';
            }
        }
        $clientId = self::positive($item['externalClientId'] ?? null) ?? 0;
        if (BillingStore::recordBridge($serverId, $requestUuid, $serviceId, $clientId, $outcome['decision'], $outcome['phase'], $outcome['state'])) {
            self::log('BladeCP commercial bridge: request=' . $requestUuid . '; service=' . $serviceId
                . '; phase=' . $outcome['phase'] . '; state=' . $outcome['state'] . '; decision=' . $outcome['decision'] . ';');
        }
        return $outcome['decision'];
    }

    /**
     * @param array<string,mixed> $work
     * @return array{decision:string,phase?:string,state?:string}
     */
    private static function apply(int $serverId, array $work): array
    {
        $clientId = self::positive($work['externalClientId'] ?? $work['external_client_id'] ?? null);
        $requestUuid = trim((string) ($work['requestUuid'] ?? $work['request_uuid'] ?? ''));
        if ($clientId === null || !self::uuid($requestUuid)) {
            return ['decision' => 'skipped: invalid work item'];
        }
        $gateway = FastDeployGateway::forClient($serverId, $clientId);
        if ($gateway !== null && method_exists($gateway, 'withBillingLinks')) {
            // Migrations billed through a link to this installation follow
            // the same lifecycle as the ones it created itself.
            $gateway = $gateway->withBillingLinks();
        }
        $detail = $gateway?->detail($requestUuid);
        if (!is_array($detail)) {
            return ['decision' => 'skipped: migration not visible to this installation'];
        }
        $seen = static fn (array $detail): array => [
            'phase' => strtolower(trim((string) ($detail['migration']['phase'] ?? ''))),
            'state' => strtolower(trim((string) ($detail['commercial']['state'] ?? ''))),
        ];
        if (method_exists(Commercial::class, 'cancelLinked') && class_exists(BillingLink::class) && BillingLink::isLinked($detail)
            && in_array(strtolower(trim((string) ($detail['migration']['phase'] ?? ''))), ['cancelled', 'failed'], true)) {
            // Cancelled or failed before Active: the pending service and its
            // unpaid invoice are closed instead of being billed.
            $cancelled = Commercial::cancelLinked($gateway, $detail);
            $decision = ($cancelled['ok'] ?? false) !== true ? 'skipped: ' . (string) ($cancelled['error'] ?? 'cancel_failed')
                : (($cancelled['status'] ?? '') === 'cancelled' ? 'cancelled before active' : 'kept: service already left pending');
            return ['decision' => $decision] + $seen($detail);
        }
        // A page need not be open for payment, late invoice and trial state to
        // converge.  Reconciliation also gives a paid invoice a chance to
        // regularize before this worker considers any native lifecycle action.
        $reconciled = Commercial::reconcile($gateway, $detail);
        if (($reconciled['ok'] ?? false) !== true && ($reconciled['error'] ?? '') !== 'payment_required') {
            return ['decision' => 'skipped: ' . (string) ($reconciled['error'] ?? 'reconcile_failed')] + $seen($detail);
        }
        $decision = self::label($reconciled, $detail);
        $detail = $gateway->detail($requestUuid);
        if (!is_array($detail)) {
            return ['decision' => $decision];
        }
        $commercial = is_array($detail['commercial'] ?? null) ? $detail['commercial'] : [];
        $policy = is_array($commercial['policy'] ?? null) ? $commercial['policy'] : [];
        $mode = strtolower(trim((string) ($policy['mode'] ?? '')));
        $expiry = is_array($policy['expiry'] ?? null) ? $policy['expiry'] : null;
        $serviceId = self::positive($commercial['externalServiceId'] ?? null);
        if ($serviceId === null) {
            return ['decision' => $decision] + $seen($detail);
        }
        $service = ServiceFields::service($serviceId, $clientId, $serverId);
        if ($service === null || !self::bindingMatches($service, $detail)) {
            return ['decision' => 'skipped: service binding does not match'] + $seen($detail);
        }
        $state = strtolower(trim((string) ($commercial['state'] ?? '')));
        $nextAction = $commercial['nextAction'] ?? null;
        // Deadlines are evaluated by the API. A local clock never authorizes
        // a native suspension or termination.
        if ($nextAction === 'payment_due' && $mode === 'trial' && $state === 'trial_active') {
            if (self::event($gateway, $requestUuid, $detail, 'payment_due', $service)) {
                $updated = $gateway->detail($requestUuid);
                if (is_array($updated)) { Commercial::reconcile($gateway, $updated); }
                return ['decision' => 'trial ended: payment due'] + $seen(is_array($updated) ? $updated : $detail);
            }
            return ['decision' => 'skipped: payment due not accepted'] + $seen($detail);
        }
        if (!in_array($nextAction, ['suspend', 'terminate'], true) || $expiry === null) { return ['decision' => $decision] + $seen($detail); }
        if ($nextAction === 'suspend' && !in_array($expiry['action'] ?? null, ['suspend', 'suspend_delete'], true)) { return ['decision' => $decision] + $seen($detail); }
        if ($nextAction === 'terminate' && ($expiry['action'] ?? null) !== 'suspend_delete') { return ['decision' => $decision] + $seen($detail); }
        $pending = $nextAction === 'suspend' ? 'suspension_pending' : 'termination_pending';
        $claim = $nextAction === 'suspend' ? 'suspension_requested' : 'termination_requested';
        if ($state !== $pending && !self::event($gateway, $requestUuid, $detail, $claim, $service)) {
            return ['decision' => 'skipped: ' . $claim . ' not accepted'] + $seen($detail);
        }
        $claimed = $gateway->detail($requestUuid);
        if (!is_array($claimed) || ($claimed['commercial']['state'] ?? '') !== $pending) {
            return ['decision' => 'skipped: ' . $claim . ' not confirmed'] + $seen($detail);
        }
        // Give a payment recorded while the claim was in flight a final chance
        // to cancel the pending action. Failed native work remains pending.
        $reconciled = Commercial::reconcile($gateway, $claimed);
        if (($reconciled['ok'] ?? false) !== true && ($reconciled['error'] ?? '') !== 'payment_required') {
            return ['decision' => 'skipped: ' . (string) ($reconciled['error'] ?? 'reconcile_failed')] + $seen($claimed);
        }
        $claimed = $gateway->detail($requestUuid);
        if (!is_array($claimed) || ($claimed['commercial']['state'] ?? '') !== $pending
            || ($claimed['commercial']['nextAction'] ?? null) !== $nextAction) {
            return ['decision' => 'payment received before ' . $nextAction] + (is_array($claimed) ? $seen($claimed) : []);
        }
        $service = ServiceFields::service($serviceId, $clientId, $serverId);
        if ($service === null || !self::bindingMatches($service, $claimed)) {
            return ['decision' => 'skipped: service binding does not match'] + $seen($claimed);
        }
        $nativeState = strtolower((string) ($service['status'] ?? ''));
        if ($nextAction === 'suspend') {
            if (in_array($nativeState, ['terminated', 'cancelled'], true)) {
                return ['decision' => 'skipped: service already closed'] + $seen($claimed);
            }
            if ($nativeState === 'suspended' || self::nativeAction('ModuleSuspend', $serviceId)) {
                self::event($gateway, $requestUuid, $claimed, 'suspended', $service);
                return ['decision' => 'suspended'] + $seen($claimed);
            }
            return ['decision' => 'skipped: native suspension failed'] + $seen($claimed);
        }
        if ($nativeState === 'terminated' || self::nativeAction('ModuleTerminate', $serviceId)) {
            self::event($gateway, $requestUuid, $claimed, 'terminated', $service);
            return ['decision' => 'terminated'] + $seen($claimed);
        }
        return ['decision' => 'skipped: native termination failed'] + $seen($claimed);
    }

    /** @param array<string,mixed> $reconciled @param array<string,mixed> $detail */
    private static function label(array $reconciled, array $detail): string
    {
        $phase = strtolower(trim((string) ($detail['migration']['phase'] ?? '')));
        if (($reconciled['ok'] ?? false) !== true) {
            return 'waiting: invoice payment';
        }
        return match ((string) ($reconciled['status'] ?? '')) {
            'trial_started' => 'trial started',
            'payment_verified' => 'payment verified',
            'regularized' => 'regularized',
            'payment_due', 'invoice_ready' => 'invoice issued',
            default => Commercial::completed($phase)
                ? 'up to date: ' . strtolower(trim((string) ($detail['commercial']['state'] ?? '')))
                : 'waiting: migration ' . $phase,
        };
    }

    private static function log(string $message): void
    {
        try {
            if (function_exists('logActivity')) {
                logActivity($message);
            }
        } catch (\Throwable) {
            // Observability never stops the reconciliation.
        }
    }

    /** @param array<string,mixed> $detail @param array<string,mixed> $service */
    private static function bindingMatches(array $service, array $detail): bool
    {
        return hash_equals('1', $service['fields'][ServiceFields::MANAGED] ?? '')
            && hash_equals((string) ($detail['requestUuid'] ?? ''), $service['fields'][ServiceFields::REQUEST_UUID] ?? '')
            && hash_equals((string) ($detail['installationId'] ?? ''), $service['fields'][ServiceFields::INSTALLATION_ID] ?? '');
    }

    /** @param array<string,mixed> $detail @param array<string,mixed> $service */
    private static function event(FastDeployGateway $gateway, string $requestUuid, array $detail, string $event, array $service): bool
    {
        try {
            $gateway->action($requestUuid, 'commercial', array_filter([
                'installation_id' => $detail['installationId'] ?? null,
                'external_client_id' => (string) $service['clientId'],
                'event' => $event,
                'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
                'invoice_id' => $service['fields'][ServiceFields::INVOICE_ID] ?? null,
            ], static fn ($value): bool => $value !== null));
            return true;
        } catch (\Throwable) {
            // A successful native action is acknowledged again on the next cron.
            return false;
        }
    }

    private static function nativeAction(string $action, int $serviceId): bool
    {
        if ($serviceId <= 0 || !function_exists('localAPI')) {
            return false;
        }
        try {
            $result = localAPI($action, ['serviceid' => $serviceId]);
            return ($result['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function positive(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value) === 1;
    }
}
