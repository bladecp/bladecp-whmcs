<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\FastDeploy;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Bridges a durable Fast Deploy request to one native WHMCS service.
 *
 * The API owns the request ledger.  WHMCS remains the billing authority, so
 * every transition is re-derived from its order, invoice and service rows.
 */
final class Commercial
{
    /** WHMCS stores the billing cycle of a service by its display name. */
    private const CYCLE_LABELS = [
        'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semiannually' => 'Semi-Annually',
        'annually' => 'Annually', 'biennially' => 'Biennially', 'triennially' => 'Triennially',
    ];

    /**
     * A migration is complete once the destination serves the site. Pointing
     * the domain is optional, so waiting for it, or that wait being stalled,
     * counts as complete for activation, the trial and the first invoice.
     */
    public const COMPLETED_PHASES = ['live', 'cutover_pending', 'cutover_stalled'];

    /** @var array<string,true> Guards reentrant use of a MySQL advisory lock in this PHP process. */
    private static array $heldLocks = [];

    /** @return array{ok:bool,status:string,serviceId?:int,orderId?:int,invoiceId?:int,error?:string,detail?:array} */
    public static function reserve(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null) {
            return self::error('invalid_request');
        }
        $phase = strtolower(trim((string) ($detail['migration']['phase'] ?? '')));
        if ($phase === 'cancelled') {
            return self::error('migration_cancelled');
        }
        // A linked migration already has its one native service. The bridge
        // only re-binds it and never creates a replacement.
        $linkedServiceId = BillingLink::isLinked($detail) ? self::externalServiceId($detail) : null;
        $release = self::acquireClientLock($context['clientId']);
        if ($release === null) {
            return self::error('lock_unavailable');
        }
        try {
            $service = null;
            if ($linkedServiceId !== null) {
                // The billing link names the one service, already proven by
                // its admin-only fields. An older reservation of the same
                // request, cancelled or marked as fraud, is history and must
                // not hide it.
                $service = ServiceFields::service($linkedServiceId, $context['clientId'], $context['serverId']);
                if ($service === null || !self::matches($service, $context)) {
                    return self::error('billing_service_missing', $linkedServiceId);
                }
                $reservation = ['state' => 'linked'];
            } else {
                $reservation = ServiceFields::findReservation($context['requestUuid'], $context['installationId'], $context['clientId'], $context['productId']);
            }
            if ($reservation['state'] === 'unavailable' || $reservation['state'] === 'conflict') {
                return self::error('reservation_' . $reservation['state']);
            }
            if ($reservation['state'] === 'found') {
                $reservedServiceId = self::positive($reservation['serviceId'] ?? null);
                if ($reservedServiceId === null || !self::assignServer($reservedServiceId, $context)) {
                    return self::error('service_server_failed', $reservedServiceId);
                }
                $service = ServiceFields::service($reservedServiceId, $context['clientId'], $context['serverId']);
                if ($service === null) {
                    return self::error('service_unavailable', $reservedServiceId);
                }
            }
            if ($service === null) {
                if ($linkedServiceId !== null) {
                    return self::error('billing_service_missing');
                }
                if ($phase === 'failed') {
                    return self::error('migration_failed');
                }
                $created = self::createService($context);
                if (!$created['ok']) {
                    return $created;
                }
                $service = ServiceFields::service($created['serviceId'], $context['clientId'], $context['serverId']);
                if ($service === null) {
                    return self::error('service_unavailable');
                }
            }
            $fields = $service['fields'];
            $bound = ServiceFields::writeBinding(
                $service['serviceId'],
                $context['clientId'],
                $context['serverId'],
                $context['requestUuid'],
                $context['installationId'],
                self::provisionUuid($detail),
                self::positive($fields[ServiceFields::INVOICE_ID] ?? null),
                true
            );
            if (($bound['ok'] ?? false) !== true) {
                return self::error((string) ($bound['reason'] ?? 'binding_failed'));
            }
            $invoiceId = self::positive(($bound['service']['fields'][ServiceFields::INVOICE_ID] ?? null));
            if ($linkedServiceId !== null) {
                // The billing link already carries this service to the
                // platform. Invoice references travel with commercial events.
                return self::ok('reserved', $service['serviceId'], $service['orderId'], $invoiceId);
            }
            $result = self::action($api, $context['requestUuid'], 'service', [
                'installation_id' => $context['installationId'],
                'external_client_id' => (string) $context['clientId'],
                'external_service_id' => (string) $service['serviceId'],
                'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
                'invoice_id' => $invoiceId === null ? null : (string) $invoiceId,
            ]);
            if ($result === null) {
                return self::error('service_link_pending', $service['serviceId'], $service['orderId'], $invoiceId);
            }
            return self::ok('reserved', $service['serviceId'], $service['orderId'], $invoiceId, $result);
        } finally {
            $release();
        }
    }

    /** Reconciles a freshly-read API detail with its one native service. */
    public static function reconcile(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null) {
            return self::error('invalid_request');
        }
        $reservation = self::reserve($api, $detail);
        if (!$reservation['ok']) {
            return $reservation;
        }
        $fresh = self::normalized($api, self::detail($api, $context['requestUuid']) ?? $detail);
        if ($fresh === null) {
            return self::error('invalid_request');
        }
        $mode = $context['mode'];
        $phase = strtolower(trim((string) (($fresh['migration']['phase'] ?? null) ?: '')));
        $state = strtolower(trim((string) (($fresh['commercial']['state'] ?? null) ?: '')));
        if (self::completed($phase) && in_array($state, ['paid', 'regularized', 'trial_active'], true)) {
            if (!self::projectLiveService($fresh, $context)) {
                return self::error('live_projection_failed');
            }
            // These states have already crossed their commercial transition.
            // Projecting WHMCS must not send payment_verified again.
            return $reservation;
        }
        // These states remain authoritative even if a later migration phase is
        // reported. A paid suspended service must regularize, while an unpaid
        // one must remain suspended; neither may restart a trial.
        if (in_array($state, ['payment_due', 'suspended', 'suspension_pending', 'termination_pending'], true)) {
            $withInvoice = self::ensureInvoice($api, $fresh);
            return $withInvoice['ok'] ? self::paid($api, $fresh) : $withInvoice;
        }
        if (in_array($state, ['terminated', 'cancelled'], true)) {
            return $reservation;
        }
        if ($mode === 'payment_before') {
            // A linked service is created without an invoice, so the bridge
            // issues the one native bill before it can verify payment.
            if (BillingLink::isLinked($fresh)) {
                $withInvoice = self::ensureInvoice($api, $fresh);
                if (!$withInvoice['ok']) {
                    return $withInvoice;
                }
            }
            return self::paid($api, $fresh);
        }
        if ($mode === 'payment_after') {
            if ($phase !== 'awaiting_approval') {
                return $reservation;
            }
            $withInvoice = self::ensureInvoice($api, $fresh);
            if (!$withInvoice['ok']) {
                return $withInvoice;
            }
            $paymentDue = self::markPaymentDue($api, $fresh);
            if (!$paymentDue['ok']) {
                return $paymentDue;
            }
            return self::paid($api, $paymentDue['detail'] ?? $fresh);
        }
        if ($mode === 'trial') {
            if (self::completed($phase) && !in_array($state, ['trial_active', 'paid', 'regularized'], true)) {
                return self::startTrial($api, $fresh);
            }
        }
        return $reservation;
    }

    /** Verifies the exact invoice, accepts the native order without autosetup and records payment with the API. */
    public static function paid(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null) {
            return self::error('invalid_request');
        }
        if (strtolower(trim((string) ($detail['commercial']['state'] ?? ''))) === 'terminated') {
            return self::error('commercial_terminated');
        }
        $serviceId = self::externalServiceId($detail);
        $service = $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($service === null || !self::matches($service, $context)) {
            return self::error('service_unavailable');
        }
        $invoiceId = self::positive($detail['commercial']['invoiceId'] ?? null)
            ?? self::positive($service['fields'][ServiceFields::INVOICE_ID] ?? null);
        if ($invoiceId === null || !self::invoicePaidForService($invoiceId, $serviceId, $context['clientId'])) {
            return self::error('payment_required', $serviceId, $service['orderId'], $invoiceId);
        }
        $accepted = self::acceptOrder($service['orderId'], BillingLink::isLinked($detail));
        if (!$accepted) {
            return self::error('order_accept_failed', $serviceId, $service['orderId'], $invoiceId);
        }
        $state = strtolower(trim((string) ($detail['commercial']['state'] ?? '')));
        $event = in_array($state, ['payment_due', 'suspended', 'suspension_pending', 'termination_pending'], true) ? 'regularized' : 'payment_verified';
        if (in_array($state, ['payment_due', 'suspended', 'suspension_pending', 'termination_pending'], true)
            && $service['status'] === 'suspended' && !self::nativeAction('ModuleUnsuspend', $serviceId)) {
            return self::error('unsuspend_failed', $serviceId, $service['orderId'], $invoiceId);
        }
        $response = self::action($api, $context['requestUuid'], 'commercial', [
            'installation_id' => $context['installationId'],
            'external_client_id' => (string) $context['clientId'],
            'event' => $event,
            'invoice_id' => (string) $invoiceId,
            'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
        ]);
        return $response === null
            ? self::error('payment_sync_pending', $serviceId, $service['orderId'], $invoiceId)
            : self::ok($event, $serviceId, $service['orderId'], $invoiceId, $response);
    }

    /** Restores only a service that the API has already marked as recoverable. */
    public static function regularize(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        if ($detail === null) {
            return self::error('service_unavailable');
        }
        $context = self::context($api, $detail);
        if (strtolower(trim((string) ($detail['commercial']['state'] ?? ''))) === 'terminated') {
            return self::error('commercial_terminated');
        }
        $serviceId = self::externalServiceId($detail);
        $service = $context === null || $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($context === null || $service === null || !self::matches($service, $context)) {
            return self::error('service_unavailable');
        }
        $invoiceId = self::positive($detail['commercial']['invoiceId'] ?? null)
            ?? self::positive($service['fields'][ServiceFields::INVOICE_ID] ?? null);
        if ($invoiceId === null || !self::invoicePaidForService($invoiceId, $serviceId, $context['clientId'])) {
            return self::error('payment_required', $serviceId, $service['orderId'], $invoiceId);
        }
        if ($service['status'] === 'suspended' && !self::nativeAction('ModuleUnsuspend', $serviceId)) {
            return self::error('unsuspend_failed', $serviceId, $service['orderId'], $invoiceId);
        }
        $response = self::action($api, $context['requestUuid'], 'commercial', [
            'installation_id' => $context['installationId'],
            'external_client_id' => (string) $context['clientId'],
            'event' => 'regularized',
            'invoice_id' => (string) $invoiceId,
            'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
        ]);
        return $response === null
            ? self::error('regularization_sync_pending', $serviceId, $service['orderId'], $invoiceId)
            : self::ok('regularized', $serviceId, $service['orderId'], $invoiceId, $response);
    }

    /** Starts a trial only after the platform reports the completed migration as live. */
    public static function startTrial(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null || $context['mode'] !== 'trial') {
            return self::error('invalid_trial');
        }
        if (!self::completed(strtolower(trim((string) ($detail['migration']['phase'] ?? ''))))) {
            return self::error('trial_not_live');
        }
        $serviceId = self::externalServiceId($detail);
        $service = $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($service === null || !self::matches($service, $context) || !self::acceptOrder($service['orderId'], BillingLink::isLinked($detail))) {
            return self::error('trial_service_unavailable');
        }
        $days = (int) ($context['policy']['trialDays'] ?? 0);
        if ($days < 1 || $days > 365 || !self::setTrialDates($serviceId, $days)) {
            return self::error('trial_dates_failed', $serviceId, $service['orderId']);
        }
        $response = self::action($api, $context['requestUuid'], 'commercial', [
            'installation_id' => $context['installationId'],
            'external_client_id' => (string) $context['clientId'],
            'event' => 'trial_started',
            'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
        ]);
        return $response === null
            ? self::error('trial_sync_pending', $serviceId, $service['orderId'])
            : self::ok('trial_started', $serviceId, $service['orderId'], null, $response);
    }

    /**
     * Reserves the one native service for a migration that will be billed
     * through a billing link. The request marker is searched first, and a
     * new service is created without a WHMCS order, so no checkout runs for
     * a request the partner never placed through the cart.
     *
     * @return array{ok:bool,status:string,serviceId?:int,orderId?:int,invoiceId?:int,error?:string}
     */
    public static function reserveLinkedService(int $serverId, int $clientId, int $productId, string $requestUuid, string $installationId, string $mode, ?string $domain = null): array
    {
        $installationId = strtolower(trim($installationId));
        if ($serverId <= 0 || $clientId <= 0 || $productId <= 0 || trim($requestUuid) === '' || $installationId === ''
            || !in_array($mode, ['payment_before', 'payment_after', 'trial'], true)) {
            return self::error('invalid_request');
        }
        $context = [
            'serverId' => $serverId, 'clientId' => $clientId, 'productId' => $productId,
            'requestUuid' => trim($requestUuid), 'installationId' => $installationId,
            'mode' => $mode, 'policy' => ['mode' => $mode], 'linked' => true, 'domain' => $domain,
        ];
        $release = self::acquireClientLock($clientId);
        if ($release === null) {
            return self::error('lock_unavailable');
        }
        try {
            $reservation = ServiceFields::findReservation($context['requestUuid'], $installationId, $clientId, $productId);
            if ($reservation['state'] === 'unavailable' || $reservation['state'] === 'conflict') {
                return self::error('reservation_' . $reservation['state']);
            }
            $serviceId = null;
            if ($reservation['state'] === 'found') {
                $serviceId = self::positive($reservation['serviceId'] ?? null);
                if ($serviceId !== null && self::closedService($serviceId)) {
                    // A cancelled, terminated or fraud-marked reservation is
                    // history, never the service to bill. An open one made
                    // after it is reused; otherwise a new one is created.
                    $serviceId = null;
                    foreach (BillingLink::reservations($context['requestUuid'], $installationId) as $open) {
                        if ($open['clientId'] === $clientId && $open['productId'] === $productId) {
                            $serviceId = $open['serviceId'];
                            break;
                        }
                    }
                }
                if ($serviceId !== null && !self::assignServer($serviceId, $context)) {
                    return self::error('service_server_failed', $serviceId);
                }
            }
            if ($serviceId === null) {
                $created = self::createLinkedService($context);
                if (!$created['ok']) {
                    return $created;
                }
                $serviceId = (int) $created['serviceId'];
            }
            $service = ServiceFields::service($serviceId, $clientId, $serverId);
            if ($service === null || !BillingLink::bound($service, $context['requestUuid'], $installationId)) {
                return self::error('service_unavailable', $serviceId);
            }
            if (in_array($service['status'], ['cancelled', 'terminated', 'fraud'], true)) {
                return self::error('service_closed', $serviceId, $service['orderId']);
            }
            // A service reserved by an earlier release may still have its order.
            if (!self::acceptOrder($service['orderId'], true)) {
                return self::error('order_accept_failed', $serviceId, $service['orderId']);
            }
            return self::ok('service_reserved', $serviceId, $service['orderId'], self::positive($service['fields'][ServiceFields::INVOICE_ID] ?? null));
        } finally {
            $release();
        }
    }

    /**
     * Closes a linked service that never reached Active: the service becomes
     * Cancelled and only unpaid invoices billing nothing but it are cancelled.
     */
    public static function cancelPendingService(int $serviceId, int $clientId, int $serverId, string $requestUuid, string $installationId): bool
    {
        $service = ServiceFields::service($serviceId, $clientId, $serverId);
        if ($service === null || !BillingLink::bound($service, trim($requestUuid), strtolower(trim($installationId)))) {
            return false;
        }
        if ($service['status'] === 'cancelled') {
            return true;
        }
        if ($service['status'] !== 'pending' || !function_exists('localAPI')) {
            return false;
        }
        foreach (self::dedicatedUnpaidInvoices($serviceId, $clientId) as $invoiceId) {
            try {
                $result = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Cancelled']);
            } catch (\Throwable) {
                return false;
            }
            if (($result['result'] ?? '') !== 'success') {
                return false;
            }
        }
        try {
            $result = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'status' => 'Cancelled']);
            return ($result['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Applies a cancelled or failed linked migration to its still-pending native service. */
    public static function cancelLinked(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null || !BillingLink::isLinked($detail)) {
            return self::error('invalid_request');
        }
        $phase = strtolower(trim((string) ($detail['migration']['phase'] ?? '')));
        if (!in_array($phase, ['cancelled', 'failed'], true)) {
            return self::error('migration_active');
        }
        if ($phase === 'failed' && ($detail['retry']['allowed'] ?? false) === true) {
            // A failure that can still be retried keeps the service for the
            // next attempt; it is closed once the retry is no longer offered.
            return self::error('migration_retryable');
        }
        $serviceId = self::externalServiceId($detail);
        $service = $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($service === null || !self::matches($service, $context)) {
            return self::error('service_unavailable');
        }
        if ($service['status'] !== 'pending' && $service['status'] !== 'cancelled') {
            // Never undo a native lifecycle that already left Pending.
            return self::ok('kept', $serviceId, $service['orderId']);
        }
        return self::cancelPendingService($serviceId, $context['clientId'], $context['serverId'], $context['requestUuid'], $context['installationId'])
            ? self::ok('cancelled', $serviceId, $service['orderId'])
            : self::error('cancel_failed', $serviceId, $service['orderId']);
    }

    /**
     * Process-local advisory lock shared with the billing import, so a
     * request is never attached twice at the same time.
     *
     * @return (\Closure():void)|null
     */
    public static function lock(string $scope, int $waitSeconds = 5): ?\Closure
    {
        $scope = trim($scope);
        return $scope === '' ? null : self::acquireLock('billing:' . $scope, $waitSeconds);
    }

    /** @return list<int> */
    private static function dedicatedUnpaidInvoices(int $serviceId, int $clientId): array
    {
        if ($serviceId <= 0 || $clientId <= 0 || !class_exists(Capsule::class)) {
            return [];
        }
        try {
            $ids = [];
            foreach (Capsule::table('tblinvoiceitems')->where('relid', $serviceId)->where('type', 'Hosting')->get() as $item) {
                $id = self::positive($item->invoiceid ?? null);
                if ($id !== null) { $ids[$id] = $id; }
            }
            $result = [];
            foreach ($ids as $invoiceId) {
                $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
                if ($invoice === null || (int) ($invoice->userid ?? 0) !== $clientId
                    || strcasecmp(trim((string) ($invoice->status ?? '')), 'Unpaid') !== 0) {
                    continue;
                }
                $dedicated = true;
                foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get() as $line) {
                    if ((int) ($line->relid ?? 0) !== $serviceId
                        || !in_array((string) ($line->type ?? ''), ['Hosting', 'Setup', 'PromoHosting'], true)) {
                        $dedicated = false;
                        break;
                    }
                }
                if ($dedicated) { $result[] = $invoiceId; }
            }
            sort($result, SORT_NUMERIC);
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * A linked view is projected onto its local service before any
     * commercial rule reads it. A linked view that cannot be proven against
     * this installation is rejected rather than treated as native.
     */
    private static function normalized(object $api, array $detail): ?array
    {
        if (!BillingLink::isLinked($detail)) {
            return $detail;
        }
        $serverId = method_exists($api, 'serverId') ? self::positive($api->serverId()) : null;
        $clientId = method_exists($api, 'clientId') ? self::positive($api->clientId()) : null;
        $installationId = method_exists($api, 'installationId') ? trim((string) $api->installationId()) : '';
        if ($serverId === null || $installationId === '') {
            return null;
        }
        return BillingLink::project($detail, $serverId, $installationId, $clientId);
    }

    /** Creates the late payment invoice, then writes its immutable native id to the service marker. */
    private static function ensureInvoice(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail);
        $context = $detail === null ? null : self::context($api, $detail);
        if ($detail === null || $context === null) {
            return self::error('invalid_request');
        }
        $serviceId = self::externalServiceId($detail);
        if ($serviceId === null) {
            return self::error('service_unavailable');
        }
        $release = self::acquireServiceLock($serviceId);
        if ($release === null) {
            return self::error('invoice_lock_unavailable', $serviceId);
        }
        try {
            $service = ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
            if ($service === null || !self::matches($service, $context)) {
                return self::error('service_unavailable');
            }
            $invoiceId = self::positive($service['fields'][ServiceFields::INVOICE_ID] ?? null)
                ?? self::existingHostingInvoiceId($serviceId, $context['clientId']);
            if ($invoiceId === null) {
                if (!function_exists('localAPI')) {
                    return self::error('local_api_unavailable');
                }
                if (!self::acceptOrder($service['orderId'], BillingLink::isLinked($detail)) || !self::setDueDate($serviceId, self::localDay(0))) {
                    return self::error('invoice_schedule_failed', $serviceId, $service['orderId']);
                }
                try {
                    $created = localAPI('GenInvoices', ['serviceids' => [$serviceId], 'noemails' => true]);
                } catch (\Throwable) {
                    return self::error('invoice_create_failed', $serviceId, $service['orderId']);
                }
                $invoiceId = self::positive($created['latestinvoiceid'] ?? null)
                    ?? self::existingHostingInvoiceId($serviceId, $context['clientId']);
                if (($created['result'] ?? '') !== 'success' || $invoiceId === null) {
                    return self::error('invoice_create_failed', $serviceId, $service['orderId']);
                }
            }
            $bound = ServiceFields::writeBinding($serviceId, $context['clientId'], $context['serverId'], $context['requestUuid'], $context['installationId'], self::provisionUuid($detail), $invoiceId);
            if (($bound['ok'] ?? false) !== true) {
                return self::error('invoice_binding_failed', $serviceId, $service['orderId'], $invoiceId);
            }
            if (BillingLink::isLinked($detail)) {
                return self::ok('invoice_ready', $serviceId, $service['orderId'], $invoiceId, $detail);
            }
            $response = self::action($api, $context['requestUuid'], 'service', [
                'installation_id' => $context['installationId'],
                'external_client_id' => (string) $context['clientId'],
                'external_service_id' => (string) $serviceId,
                'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
                'invoice_id' => (string) $invoiceId,
            ]);
            return $response === null ? self::error('invoice_sync_pending', $serviceId, $service['orderId'], $invoiceId)
                : self::ok('invoice_ready', $serviceId, $service['orderId'], $invoiceId, $response);
        } finally {
            $release();
        }
    }

    /** Sets the platform deadline only after the native invoice is bound. */
    private static function markPaymentDue(object $api, array $detail): array
    {
        $detail = self::normalized($api, $detail) ?? [];
        $context = self::context($api, $detail);
        $serviceId = self::externalServiceId($detail);
        $service = $context === null || $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($context === null || $service === null || !self::matches($service, $context)) {
            return self::error('service_unavailable');
        }
        $invoiceId = self::positive($service['fields'][ServiceFields::INVOICE_ID] ?? null);
        if ($invoiceId === null) {
            return self::error('invoice_unavailable', $serviceId, $service['orderId']);
        }
        $state = strtolower(trim((string) ($detail['commercial']['state'] ?? '')));
        if ($state === 'payment_due') {
            return self::ok('payment_due', $serviceId, $service['orderId'], $invoiceId, $detail);
        }
        $response = self::action($api, $context['requestUuid'], 'commercial', [
            'installation_id' => $context['installationId'],
            'external_client_id' => (string) $context['clientId'],
            'event' => 'payment_due',
            'invoice_id' => (string) $invoiceId,
            'order_id' => $service['orderId'] > 0 ? (string) $service['orderId'] : null,
        ]);
        return $response === null
            ? self::error('payment_due_sync_pending', $serviceId, $service['orderId'], $invoiceId)
            : self::ok('payment_due', $serviceId, $service['orderId'], $invoiceId, $response);
    }

    /** @return array{ok:bool,status:string,serviceId?:int,orderId?:int,invoiceId?:int,error?:string} */
    private static function createService(array $context): array
    {
        if (!function_exists('localAPI') || self::paymentMethod($context['clientId']) === null) {
            return self::error('payment_method_unavailable');
        }
        $cycle = self::billingCycle($context['clientId'], $context['productId']);
        $fields = ServiceFields::ensureForProduct($context['productId']);
        if ($cycle === null || $fields === null) {
            return self::error('native_commerce_unavailable');
        }
        $withInvoice = $context['mode'] === 'payment_before';
        $customFields = base64_encode(serialize([
            $fields[ServiceFields::MANAGED] => '1',
            $fields[ServiceFields::REQUEST_UUID] => $context['requestUuid'],
            $fields[ServiceFields::INSTALLATION_ID] => $context['installationId'],
        ]));
        try {
            $order = localAPI('AddOrder', [
                'clientid' => $context['clientId'],
                'paymentmethod' => self::paymentMethod($context['clientId']),
                'pid' => [$context['productId']],
                'qty' => [1],
                'billingcycle' => [$cycle],
                'customfields' => [$customFields],
                'noinvoice' => !$withInvoice,
                'noinvoiceemail' => true,
                'noemail' => true,
            ]);
        } catch (\Throwable) {
            return self::error('order_create_failed');
        }
        $serviceId = self::firstId($order['serviceids'] ?? null);
        $orderId = self::positive($order['orderid'] ?? null);
        $invoiceId = self::positive($order['invoiceid'] ?? null);
        if (($order['result'] ?? '') !== 'success' || $serviceId === null || $orderId === null) {
            return self::error('order_create_failed');
        }
        if (!self::assignServer($serviceId, $context)) {
            return self::error('service_server_failed', $serviceId, $orderId, $invoiceId);
        }
        $binding = ServiceFields::writeBinding($serviceId, $context['clientId'], $context['serverId'], $context['requestUuid'], $context['installationId'], null, $invoiceId);
        if (($binding['ok'] ?? false) !== true) {
            return self::error('marker_write_failed', $serviceId, $orderId, $invoiceId);
        }
        if (!$withInvoice && !self::setDueDate($serviceId, self::localDay(365))) {
            return self::error('neutral_due_date_failed', $serviceId, $orderId, $invoiceId);
        }
        return self::ok('service_created', $serviceId, $orderId, $invoiceId);
    }

    /**
     * Creates the service of a linked migration without a WHMCS order, the
     * way imported accounts are created. AddOrder runs the cart checkout and
     * its hooks with the IP of whoever is browsing: fraud screening there
     * could reject the order and ban the administrator or the client. No
     * order means no checkout, no fraud screening and no IP to ban.
     *
     * The row and its admin-only correlation are written in one transaction,
     * so a retry always finds the reservation instead of creating another.
     *
     * @return array{ok:bool,status:string,serviceId?:int,error?:string}
     */
    private static function createLinkedService(array $context): array
    {
        $paymentMethod = self::paymentMethod($context['clientId'], true);
        if ($paymentMethod === null) {
            return self::error('payment_method_unavailable');
        }
        $price = self::price($context['clientId'], $context['productId']);
        $fields = ServiceFields::ensureForProduct($context['productId']);
        if ($price === null || $fields === null || !class_exists(Capsule::class)) {
            return self::error('native_commerce_unavailable');
        }
        $domain = strtolower(trim((string) ($context['domain'] ?? '')));
        $neutral = self::localDay(365);
        $now = date('Y-m-d H:i:s');
        $row = [
            'userid' => $context['clientId'], 'orderid' => 0, 'packageid' => $context['productId'], 'server' => $context['serverId'],
            'regdate' => self::localDay(0), 'domain' => self::validDomain($domain) ? $domain : '', 'paymentmethod' => $paymentMethod,
            'qty' => 1, 'firstpaymentamount' => number_format($price['amount'] + $price['setup'], 2, '.', ''),
            'amount' => number_format($price['amount'], 2, '.', ''), 'billingcycle' => self::CYCLE_LABELS[$price['cycle']],
            // No bill is due until the reconciliation sets the real date.
            'nextduedate' => $neutral, 'nextinvoicedate' => $neutral, 'domainstatus' => 'Pending',
            'username' => '', 'password' => '', 'notes' => 'BladeCP cPanel Migrator migration, created without an order.',
            'subscriptionid' => '', 'promoid' => 0, 'suspendreason' => '', 'overideautosuspend' => 0,
            'overidesuspenduntil' => '0000-00-00', 'termination_date' => '0000-00-00', 'completed_date' => '0000-00-00',
            'dedicatedip' => '', 'assignedips' => '', 'ns1' => '', 'ns2' => '',
            'diskusage' => 0, 'disklimit' => 0, 'bwusage' => 0, 'bwlimit' => 0,
            'lastupdate' => '0000-00-00 00:00:00', 'created_at' => $now, 'updated_at' => $now,
        ];
        try {
            $columns = array_flip(Capsule::schema()->getColumnListing('tblhosting'));
            foreach (['userid', 'orderid', 'packageid', 'server', 'domainstatus', 'billingcycle', 'nextduedate'] as $column) {
                if (!isset($columns[$column])) {
                    return self::error('native_commerce_unavailable');
                }
            }
            $serviceId = (int) Capsule::connection()->transaction(static function () use ($row, $columns, $fields, $context): int {
                $serviceId = (int) Capsule::table('tblhosting')->insertGetId(array_intersect_key($row, $columns));
                if ($serviceId <= 0) {
                    throw new \RuntimeException('service_create_failed');
                }
                foreach ([ServiceFields::MANAGED => '1', ServiceFields::REQUEST_UUID => $context['requestUuid'],
                    ServiceFields::INSTALLATION_ID => $context['installationId']] as $name => $value) {
                    Capsule::table('tblcustomfieldsvalues')->insert(['fieldid' => $fields[$name], 'relid' => $serviceId, 'value' => $value]);
                }
                return $serviceId;
            });
        } catch (\Throwable) {
            return self::error('service_create_failed');
        }
        if (function_exists('logActivity')) {
            try {
                logActivity('BladeCP migration billing: service #' . $serviceId . ' created without an order for request ' . $context['requestUuid']);
            } catch (\Throwable) {
                // Observability never undoes the reservation.
            }
        }
        return self::ok('service_created', $serviceId);
    }

    /** Uses exactly the cycle whose price was shown in the client's native currency. */
    private static function billingCycle(int $clientId, int $productId): ?string
    {
        return self::price($clientId, $productId)['cycle'] ?? null;
    }

    /** @return array{cycle:string,amount:float,setup:float}|null */
    private static function price(int $clientId, int $productId): ?array
    {
        if ($clientId <= 0 || $productId <= 0 || !function_exists('getCurrency') || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $currency = getCurrency($clientId);
            $currencyId = self::positive(is_array($currency) ? ($currency['id'] ?? null) : null);
            if ($currencyId === null) {
                return null;
            }
            $row = Capsule::table('tblpricing')->where('type', 'product')->where('relid', $productId)->where('currency', $currencyId)->first();
            $price = \WHMCS\Module\Addon\Bladecp\FastDeployPresentation::basePrice($row === null ? [] : (array) $row);
            $cycle = is_array($price) ? ($price['cycle'] ?? null) : null;
            if (!is_string($cycle) || !array_key_exists($cycle, \WHMCS\Module\Addon\Bladecp\PriceMap::SETUP_MAP) || !isset(self::CYCLE_LABELS[$cycle])) {
                return null;
            }
            return ['cycle' => $cycle, 'amount' => (float) $price['amount'], 'setup' => (float) ($price['setup'] ?? 0)];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Binds an AddOrder-created service to this selected server, never moves an existing foreign service. */
    private static function assignServer(int $serviceId, array $context): bool
    {
        if ($serviceId <= 0 || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            $row = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($row === null || (int) ($row->userid ?? 0) !== $context['clientId'] || (int) ($row->packageid ?? 0) !== $context['productId']) {
                return false;
            }
            $currentServer = (int) ($row->server ?? 0);
            if ($currentServer === $context['serverId']) {
                return true;
            }
            if ($currentServer !== 0 || !function_exists('localAPI')) {
                return false;
            }
            $result = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'serverid' => $context['serverId']]);
            if (($result['result'] ?? '') !== 'success') {
                return false;
            }
            $updated = Capsule::table('tblhosting')->where('id', $serviceId)->first();
            return $updated !== null && (int) ($updated->userid ?? 0) === $context['clientId']
                && (int) ($updated->packageid ?? 0) === $context['productId'] && (int) ($updated->server ?? 0) === $context['serverId'];
        } catch (\Throwable) {
            return false;
        }
    }

    /** Reflects a confirmed live migration in the existing native service only. */
    private static function projectLiveService(array $detail, array $context): bool
    {
        $serviceId = self::externalServiceId($detail);
        $service = $serviceId === null ? null : ServiceFields::service($serviceId, $context['clientId'], $context['serverId']);
        if ($service === null || !self::matches($service, $context)) {
            return false;
        }
        // Never infer reactivation from a live phase. The native lifecycle is
        // authoritative once it has left Pending.
        if ($service['status'] !== 'pending') {
            return true;
        }
        if (!function_exists('localAPI')) {
            return false;
        }
        $payload = ['serviceid' => $serviceId, 'status' => 'Active'];
        $domain = trim((string) ($detail['migration']['primaryDomain'] ?? ''));
        if (self::validDomain($domain)) {
            $payload['domain'] = strtolower($domain);
        }
        try {
            $result = localAPI('UpdateClientProduct', $payload);
            return ($result['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function validDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '/') || str_contains($domain, '@')) {
            return false;
        }
        return preg_match('/^(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/Di', $domain) === 1;
    }

    private static function closedService(int $serviceId): bool
    {
        try {
            $status = strtolower(trim((string) Capsule::table('tblhosting')->where('id', $serviceId)->value('domainstatus')));
            return in_array($status, ['cancelled', 'terminated', 'fraud'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function completed(string $phase): bool
    {
        return in_array(strtolower(trim($phase)), self::COMPLETED_PHASES, true);
    }

    /** An orderless linked service has nothing to accept; every other service needs its exact order. */
    private static function acceptOrder(int $orderId, bool $orderless = false): bool
    {
        if ($orderId === 0 && $orderless) {
            return true;
        }
        if ($orderId <= 0 || !function_exists('localAPI') || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            $order = Capsule::table('tblorders')->where('id', $orderId)->first();
            $status = strtolower(trim((string) ($order->status ?? '')));
            if ($status === 'active') {
                return true;
            }
            if ($status !== 'pending') {
                return false;
            }
            $result = localAPI('AcceptOrder', ['orderid' => $orderId, 'autosetup' => false, 'sendemail' => false]);
            return ($result['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function invoicePaidForService(int $invoiceId, int $serviceId, int $clientId): bool
    {
        if ($invoiceId <= 0 || $serviceId <= 0 || $clientId <= 0 || !class_exists(Capsule::class)) {
            return false;
        }
        try {
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            if ($invoice === null || (int) ($invoice->userid ?? 0) !== $clientId
                || strcasecmp(trim((string) ($invoice->status ?? '')), 'Paid') !== 0) {
                return false;
            }
            return Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)
                ->where('relid', $serviceId)->where('type', 'Hosting')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Finds the native Hosting invoice created before a previous response was
     * lost.  This makes retries recover the existing bill instead of issuing a
     * second one for the same reserved service.
     */
    private static function existingHostingInvoiceId(int $serviceId, int $clientId): ?int
    {
        if ($serviceId <= 0 || $clientId <= 0 || !class_exists(Capsule::class)) {
            return null;
        }
        try {
            $invoiceIds = [];
            foreach (Capsule::table('tblinvoiceitems')->where('type', 'Hosting')->where('relid', $serviceId)->get() as $item) {
                $id = self::positive($item->invoiceid ?? null);
                if ($id !== null) {
                    $invoiceIds[] = $id;
                }
            }
            rsort($invoiceIds, SORT_NUMERIC);
            foreach ($invoiceIds as $invoiceId) {
                $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
                $status = strtolower(trim((string) ($invoice->status ?? '')));
                if ($invoice !== null && (int) ($invoice->userid ?? 0) === $clientId
                    && !in_array($status, ['cancelled', 'refunded'], true)) {
                    return $invoiceId;
                }
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setTrialDates(int $serviceId, int $days): bool
    {
        if ($serviceId <= 0 || $days < 1 || $days > 365 || !function_exists('localAPI')) {
            return false;
        }
        return self::setDueDate($serviceId, self::localDay($days));
    }

    /**
     * Calendar day in the WHMCS installation's zone (PHP default timezone,
     * which WHMCS configures), plus `$plusDays`. The due date used to come
     * from gmdate(): after 21:00 in Brasília UTC is already tomorrow, so a
     * service bought at 22:00 was billed one day later for its whole life.
     */
    private static function localDay(int $plusDays, ?\DateTimeImmutable $now = null): string
    {
        $today = ($now ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0);
        return $today->modify('+' . $plusDays . ' days')->format('Y-m-d');
    }

    private static function setDueDate(int $serviceId, string $date): bool
    {
        if ($serviceId <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || !function_exists('localAPI')) {
            return false;
        }
        try {
            $result = localAPI('UpdateClientProduct', ['serviceid' => $serviceId, 'nextduedate' => $date]);
            return ($result['result'] ?? '') === 'success';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{serverId:int,clientId:int,productId:int,requestUuid:string,installationId:string,mode:string,policy:array}|null */
    private static function context(object $api, array $detail): ?array
    {
        $serverId = method_exists($api, 'serverId') ? self::positive($api->serverId()) : null;
        $clientId = method_exists($api, 'clientId') ? self::positive($api->clientId()) : null;
        $productId = self::positive($detail['nativeProductId'] ?? null);
        $requestUuid = trim((string) ($detail['requestUuid'] ?? ''));
        $installationId = trim((string) ($detail['installationId'] ?? ''));
        $externalClient = trim((string) ($detail['externalClientId'] ?? ''));
        $policy = $detail['commercial']['policy'] ?? null;
        $mode = is_array($policy) ? (string) ($policy['mode'] ?? '') : '';
        if ($serverId === null || $clientId === null || $productId === null || $requestUuid === '' || $installationId === ''
            || !hash_equals((string) $clientId, $externalClient) || !in_array($mode, ['payment_before', 'payment_after', 'trial'], true)) {
            return null;
        }
        return compact('serverId', 'clientId', 'productId', 'requestUuid', 'installationId', 'mode', 'policy');
    }

    private static function matches(array $service, array $context): bool
    {
        return $service['clientId'] === $context['clientId'] && $service['serverId'] === $context['serverId']
            && $service['productId'] === $context['productId']
            && hash_equals($context['requestUuid'], $service['fields'][ServiceFields::REQUEST_UUID] ?? '')
            && hash_equals($context['installationId'], $service['fields'][ServiceFields::INSTALLATION_ID] ?? '')
            && hash_equals('1', $service['fields'][ServiceFields::MANAGED] ?? '');
    }

    private static function externalServiceId(array $detail): ?int
    {
        return self::positive($detail['commercial']['externalServiceId'] ?? null);
    }

    private static function provisionUuid(array $detail): ?string
    {
        $value = trim((string) ($detail['migration']['provisionUuid'] ?? ''));
        return $value === '' ? null : $value;
    }

    private static function paymentMethod(int $clientId, bool $firstActive = false): ?string
    {
        try {
            $value = trim((string) Capsule::table('tblclients')->where('id', $clientId)->value('defaultgateway'));
            if ($value === '') {
                $value = trim((string) ($GLOBALS['CONFIG']['DefaultPaymentGateway'] ?? ''));
            }
            if ($value === '' && $firstActive && function_exists('localAPI')) {
                // A client created for a linked migration has no gateway of
                // its own yet; WHMCS then bills with its first active one.
                $methods = localAPI('GetPaymentMethods', []);
                $first = $methods['paymentmethods']['paymentmethod'][0]['module'] ?? '';
                $value = is_string($first) ? trim($first) : '';
            }
            return $value === '' ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function detail(object $api, string $requestUuid): ?array
    {
        try {
            $detail = $api->detail($requestUuid);
            return is_array($detail) ? $detail : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function action(object $api, string $requestUuid, string $action, array $payload): ?array
    {
        try {
            $result = $api->action($requestUuid, $action, array_filter($payload, static fn ($value): bool => $value !== null));
            return is_array($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function firstId(mixed $value): ?int
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (($id = self::positive($item)) !== null) { return $id; }
            }
        }
        return self::positive($value);
    }

    private static function positive(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return (\Closure():void)|null */
    private static function acquireClientLock(int $clientId): ?\Closure
    {
        return self::acquireLock('client:' . $clientId);
    }

    /** @return (\Closure():void)|null */
    private static function acquireServiceLock(int $serviceId): ?\Closure
    {
        return $serviceId > 0 ? self::acquireLock('service:' . $serviceId) : null;
    }

    /** @return (\Closure():void)|null */
    private static function acquireLock(string $scope, int $waitSeconds = 5): ?\Closure
    {
        if ($scope === '' || !class_exists(Capsule::class)) { return null; }
        if (isset(self::$heldLocks[$scope])) { return null; }
        try {
            $connection = Capsule::connection();
            $driver = strtolower((string) $connection->getDriverName());
            if ($driver === 'mysql') {
                $name = 'bladecp_fd_' . substr(hash('sha256', $scope), 0, 32);
                $rows = $connection->select('SELECT GET_LOCK(?, ?) AS acquired', [$name, max(0, min(5, $waitSeconds))]);
                $row = $rows[0] ?? null;
                if ((int) (is_object($row) ? ($row->acquired ?? 0) : ($row['acquired'] ?? 0)) !== 1) { return null; }
                self::$heldLocks[$scope] = true;
                return static function () use ($connection, $name, $scope): void {
                    try { $connection->select('SELECT RELEASE_LOCK(?)', [$name]); } catch (\Throwable) {}
                    unset(self::$heldLocks[$scope]);
                };
            }
            if ($driver !== 'sqlite' && !(PHP_SAPI === 'cli' && $driver === '')) { return null; }
        } catch (\Throwable) { return null; }
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bladecp-fast-deploy-' . hash('sha256', $scope) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) { if ($handle !== false) { @fclose($handle); } return null; }
        self::$heldLocks[$scope] = true;
        return static function () use ($handle, $scope): void {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            unset(self::$heldLocks[$scope]);
        };
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

    private static function ok(string $status, ?int $serviceId = null, ?int $orderId = null, ?int $invoiceId = null, ?array $detail = null): array
    {
        return array_filter(['ok' => true, 'status' => $status, 'serviceId' => $serviceId, 'orderId' => $orderId, 'invoiceId' => $invoiceId, 'detail' => $detail], static fn ($value): bool => $value !== null);
    }

    private static function error(string $error, ?int $serviceId = null, ?int $orderId = null, ?int $invoiceId = null): array
    {
        return array_filter(['ok' => false, 'status' => 'error', 'error' => $error, 'serviceId' => $serviceId, 'orderId' => $orderId, 'invoiceId' => $invoiceId], static fn ($value): bool => $value !== null);
    }
}
