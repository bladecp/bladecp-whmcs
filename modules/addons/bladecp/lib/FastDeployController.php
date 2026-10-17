<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Request boundary for the Fast Deploy client route. The outer client-area
 * callback decides how an accepted context is rendered.
 */
final class FastDeployController
{
    private const MAX_BACKUP_BYTES = 53687091200;

    private const ACTIONS = [
        'create', 'upload-url', 'complete-upload', 'confirm-upload', 'discovery-review',
        'approve', 'cancel', 'retry', 'reconcile',
    ];

    /**
     * Returns a safe render context, or null when the authenticated route is
     * unavailable. It deliberately does not trust ids or commercial state
     * supplied by the browser.
     *
     * @return array<string,mixed>|null
     */
    public static function handle(): ?array
    {
        try {
            if (!self::prepareJsonInput()) {
                return self::failure(self::copyText('error_invalid_data'));
            }
            $identity = IdentityHandoff::currentIdentity();
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            if ($identity === null || ($context['state'] ?? '') !== 'configured' || $serverId <= 0
                || !ClientExperienceSettings::fastDeployEnabled($serverId)) {
                return null;
            }
            $gateway = FastDeployGateway::forClient($serverId, (int) $identity['clientId']);
            if ($gateway === null) {
                return self::failure(self::copyText('error_unavailable_now'));
            }
            if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
                return self::read($gateway);
            }
            return self::mutate($gateway, $identity);
        } catch (\Throwable) {
            self::logFailure('handle');
            return self::failure(self::copyText('error_action_failed'));
        }
    }

    /** Returns WHMCS' native token value for JSON and form clients. */
    public static function csrfToken(): string
    {
        try {
            return function_exists('generate_token') ? (string) generate_token('plain') : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return string|null Stable for a short double-submit window and bound to the selected destination. */
    public static function nonce(array $selection = []): ?string
    {
        try {
            $identity = IdentityHandoff::currentIdentity();
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            if ($identity === null || ($context['state'] ?? '') !== 'configured' || $serverId <= 0
                || !ClientExperienceSettings::fastDeployEnabled($serverId)) {
                return null;
            }
            $gateway = FastDeployGateway::forClient($serverId, (int) $identity['clientId']);
            $new = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
                && ($_GET['new'] ?? null) === '1';
            return $gateway === null ? null : self::createNonce($gateway, $identity, $new);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private static function read(FastDeployGateway $gateway): array
    {
        $request = $_GET['request'] ?? null;
        if (is_string($request) && $request !== '') {
            $detail = $gateway->detail($request);
            return $detail === null ? self::failure(self::copyText('error_request_unavailable'))
                : self::success(self::present($gateway, $detail), $gateway->list());
        }
        return ['ok' => true, 'data' => null, 'detail' => null, 'history' => $gateway->list()];
    }

    /** @param array{userId:int,clientId:int} $identity @return array<string,mixed> */
    private static function mutate(FastDeployGateway $gateway, array $identity): array
    {
        if (!self::validCsrf()) {
            return self::failure(self::copyText('error_action_unvalidated'));
        }
        $operation = $_POST['operation'] ?? null;
        if (!is_string($operation) || !in_array($operation, self::ACTIONS, true)) {
            return self::failure(self::copyText('error_invalid_action'));
        }
        if ($operation === 'create') {
            // Legacy no-JS plan refreshes must never fall through to creation.
            // The selected destination is revalidated when the actual create
            // form is submitted, so no mutation is needed to refresh it.
            if (array_key_exists('refresh_plan', $_POST)) {
                return self::failure(self::copyText('error_review_destination'));
            }
            return self::create($gateway, $identity);
        }
        $request = $_POST['request'] ?? null;
        if (!is_string($request) || !self::uuid($request)) {
            return self::failure(self::copyText('error_invalid_request'));
        }
        $current = $gateway->detail($request);
        if ($current === null) {
            return self::failure(self::copyText('error_request_unavailable'));
        }
        if ($operation === 'reconcile') {
            if (($current['actions']['reconcile'] ?? false) !== true
                || !class_exists(\WHMCS\Module\Addon\Bladecp\FastDeploy\Commercial::class)) {
                return self::failure(self::copyText('error_commercial_unavailable'));
            }
            $commercial = \WHMCS\Module\Addon\Bladecp\FastDeploy\Commercial::reconcile($gateway, $current);
            $fresh = $gateway->detail($request);
            if ((($commercial['ok'] ?? false) !== true && !self::paymentPending($commercial)) || $fresh === null) {
                return self::failure(self::copyText('error_commercial_retry'), [
                    'requestUuid' => $request,
                    'detail' => $current,
                ]);
            }
            return self::success(self::present($gateway, $fresh), $gateway->list(), $commercial);
        }
        $payload = self::payload($operation);
        if ($payload === null) {
            return self::failure(self::copyText('error_invalid_data'));
        }
        $operationId = null;
        if (in_array($operation, ['upload-url', 'retry'], true)) {
            $operationId = $_POST['operation_id'] ?? null;
            if (!is_string($operationId) || !self::uuid($operationId)) {
                return self::failure(self::copyText('error_action_unprepared'));
            }
        }
        $key = self::actionKey($gateway, $identity, $request, $operation, $payload, $operationId);
        if ($key === null) {
            return self::failure(self::copyText('error_action_unprepared'));
        }
        $result = $gateway->action($request, $operation, $payload, $key);
        if ($operation === 'upload-url') {
            return ['ok' => true, 'data' => $result, 'uploadPlan' => $result, 'detail' => self::present($gateway, $current), 'history' => $gateway->list()];
        }
        $nextRequest = is_string($result['requestUuid'] ?? null) ? $result['requestUuid'] : $request;
        $fresh = $gateway->detail($nextRequest);
        if ($fresh === null) {
            return self::failure(self::copyText('error_action_tracking'), [
                'requestUuid' => $nextRequest,
                'detail' => $current,
            ]);
        }
        return self::success(self::present($gateway, $fresh), $gateway->list());
    }

    /** @param array{userId:int,clientId:int} $identity @return array<string,mixed> */
    private static function create(FastDeployGateway $gateway, array $identity): array
    {
        $catalog = FastDeployDestinations::load($gateway->serverId());
        $selection = FastDeployDestinations::selection($catalog, $_POST);
        if ($selection === null) {
            return self::failure(self::copyText('error_destination_unavailable'));
        }
        $policy = FastDeployPolicy::forProduct($gateway->serverId(), (int) $selection['product']['id']);
        if ($policy === null) {
            return self::failure(self::copyText('error_plan_not_enabled'));
        }
        $payload = self::createPayload(
            $gateway,
            $selection,
            $policy,
            is_string($_POST['primary_domain'] ?? null) ? $_POST['primary_domain'] : null
        );
        if ($payload === null) {
            return self::failure(self::copyText('error_destination_invalid'));
        }
        // A browser retry of the form keeps the consumed nonce valid long
        // enough for API idempotency. Only a subsequent GET collection view
        // rotates it for a new migration journey.
        $nonce = self::createNonce($gateway, $identity, false, true);
        $provided = $_POST['request_id'] ?? null;
        if ($nonce === null || !is_string($provided) || !hash_equals($nonce, $provided)) {
            return self::failure(self::copyText('error_request_unprepared'));
        }
        $payload['request_id'] = $nonce;
        $result = $gateway->create($payload, 'whmcs-fast-deploy-' . $gateway->clientId() . '-' . $nonce);
        self::markCreateNonceConsumed($gateway, $identity, $nonce);
        $requestUuid = is_string($result['requestUuid'] ?? null) ? $result['requestUuid'] : $nonce;
        $initial = $gateway->detail($requestUuid);
        if ($initial === null) {
            return self::failure(self::copyText('error_migration_reload'), [
                'requestUuid' => $requestUuid,
                'detail' => $result,
            ]);
        }
        if (!class_exists(\WHMCS\Module\Addon\Bladecp\FastDeploy\Commercial::class)) {
            return self::failure(self::copyText('error_migration_purchase'), [
                'requestUuid' => $requestUuid,
                'detail' => $initial,
            ]);
        }
        $commercial = \WHMCS\Module\Addon\Bladecp\FastDeploy\Commercial::reserve($gateway, $initial);
        if (($commercial['ok'] ?? false) !== true) {
            return self::failure(self::copyText('error_migration_purchase'), [
                'requestUuid' => $requestUuid,
                'detail' => $initial,
            ]);
        }
        $fresh = $gateway->detail($requestUuid);
        if ($fresh === null) {
            return self::failure(self::copyText('error_migration_tracking'), [
                'requestUuid' => $requestUuid,
                'detail' => $initial,
            ]);
        }
        return self::success(self::present($gateway, $fresh), $gateway->list(), $commercial);
    }

    /**
     * Builds the create payload after the caller has reloaded and validated
     * the destination through FastDeployDestinations::selection().
     *
     * @return array<string,mixed>|null
     */
    public static function createPayload(
        FastDeployGateway $gateway,
        array $selection,
        array $policy,
        ?string $primaryDomain = null
    ): ?array {
        $owner = FastDeployGateway::owner($gateway->clientId());
        $product = $selection['product'] ?? null;
        $location = $selection['location'] ?? null;
        $commercial = FastDeployPolicy::rule($policy);
        if ($owner === null || !is_array($product) || !is_array($location)
            || !is_int($product['id'] ?? null) || !is_int($product['planId'] ?? null)
            || !is_int($location['id'] ?? null) || $commercial === null) {
            return null;
        }
        $domain = $primaryDomain === null ? null : strtolower(trim($primaryDomain));
        if ($domain !== null && ($domain === '' || strlen($domain) > 63 || preg_match('/\s|[\x00-\x1F\x7F]/', $domain) === 1)) {
            return null;
        }
        return [
            'request_id' => self::uuidV4(),
            'installation_id' => $gateway->installationId(),
            'external_client_id' => (string) $gateway->clientId(),
            'owner' => $owner,
            'native_product_id' => $product['id'],
            'partner_plan_id' => $product['planId'],
            'location_id' => $location['id'],
            'source_panel' => 'cpanel',
            'primary_domain' => $domain,
            'commercial' => self::commercialPayload($commercial),
        ];
    }

    /** Converts local nullable settings to the Customer API's optional fields. */
    private static function commercialPayload(array $policy): array
    {
        $payload = ['mode' => $policy['mode']];
        if ($policy['mode'] === 'trial') {
            $payload['trial_days'] = $policy['trial_days'];
        } elseif ($policy['mode'] === 'payment_after') {
            $payload['payment_days'] = $policy['payment_days'];
        }
        if (is_array($policy['expiry'] ?? null)) {
            $expiry = ['action' => $policy['expiry']['action'], 'grace_days' => $policy['expiry']['grace_days']];
            if ($policy['expiry']['action'] === 'suspend_delete') {
                $expiry['retention_days'] = $policy['expiry']['retention_days'];
            }
            $payload['expiry'] = $expiry;
        }
        return $payload;
    }

    /** @return array<string,mixed>|null */
    private static function payload(string $operation): ?array
    {
        $allowed = match ($operation) {
            'upload-url' => ['sizeBytes', 'filename'],
            'complete-upload' => ['parts'],
            'discovery-review' => ['primaryDomain'],
            default => [],
        };
        $payload = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $_POST)) {
                $payload[$key] = $_POST[$key];
            }
        }
        if ($operation === 'complete-upload' && !isset($payload['parts']) && is_string($_POST['parts_json'] ?? null)) {
            $rawParts = $_POST['parts_json'];
            if (strlen($rawParts) > 2097152) { return null; }
            try { $payload['parts'] = json_decode($rawParts, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return null; }
        }
        if ($operation === 'upload-url') {
            $sizeBytes = self::backupBytes($payload['sizeBytes'] ?? null);
            if (!is_string($payload['filename'] ?? null) || trim($payload['filename']) === ''
                || strlen($payload['filename']) > 255 || $sizeBytes === null) {
                return null;
            }
            $payload['sizeBytes'] = $sizeBytes;
        }
        if ($operation === 'complete-upload') {
            $parts = $payload['parts'] ?? null;
            if (!is_array($parts) || count($parts) < 1 || count($parts) > 10000) { return null; }
            foreach ($parts as $part) {
                if (!is_array($part) || !is_int($part['partNumber'] ?? null) || $part['partNumber'] < 1
                    || !is_string($part['etag'] ?? null) || trim($part['etag']) === '' || strlen($part['etag']) > 512) {
                    return null;
                }
            }
        }
        if ($operation === 'discovery-review' && (!is_string($payload['primaryDomain'] ?? null)
            || trim($payload['primaryDomain']) === '')) {
            return null;
        }
        // Keep the raw form field out of all downstream calls only after its
        // decoded parts have passed the local shape checks above.
        if ($operation === 'complete-upload') { unset($_POST['parts_json']); }
        return $payload;
    }

    /** A linked prepayment invoice is a normal customer-visible waiting state. */
    private static function paymentPending(array $commercial): bool
    {
        return ($commercial['error'] ?? null) === 'payment_required';
    }

    /** Accepts an API-safe positive byte count from native form or JSON input. */
    private static function backupBytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= self::MAX_BACKUP_BYTES ? $value : null;
        }
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return null;
        }
        $maximum = (string) self::MAX_BACKUP_BYTES;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }
        return (int) $normalized;
    }

    /** Records only the failed boundary, never request data, URLs or credentials. */
    private static function logFailure(string $stage): void
    {
        try {
            Logger::logCall('fast_deploy_controller', ['stage' => $stage], ['error' => 'unexpected_failure']);
        } catch (\Throwable) {
            // Logging must not affect the authenticated request boundary.
        }
    }

    private static function validCsrf(): bool
    {
        try {
            return function_exists('check_token') && check_token('WHMCS.default') !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private static function success(array $detail, array $history, ?array $commercial = null): array
    {
        $result = ['ok' => true, 'data' => $detail, 'detail' => $detail, 'history' => $history];
        if ($commercial !== null) { $result['commercial'] = $commercial; }
        return $result;
    }

    /** @return array{ok:false,error:string,data:?array,detail?:array,requestUuid?:string} */
    /** Customer-facing message in the language of the current session. */
    private static function copyText(string $key): string
    {
        return (string) (FastDeployClientArea::copy()[$key] ?? $key);
    }

    private static function failure(string $message, ?array $data = null): array
    {
        $result = ['ok' => false, 'data' => $data, 'error' => $message];
        if ($data !== null && is_string($data['requestUuid'] ?? null)) {
            $result['requestUuid'] = $data['requestUuid'];
        }
        if ($data !== null && is_array($data['detail'] ?? null)) {
            $result['detail'] = $data['detail'];
        }
        return $result;
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value) === 1;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function createNonce(FastDeployGateway $gateway, array $identity, bool $replace = false, bool $allowConsumed = false): ?string
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return null;
        }
        $key = implode(':', [$gateway->serverId(), $identity['clientId']]);
        $all = $_SESSION['bladecp_fast_deploy_create'] ?? [];
        if (!is_array($all)) { $all = []; }
        $current = $all[$key] ?? null;
        if (!$replace && is_array($current) && self::uuid((string) ($current['request'] ?? ''))
            && (int) ($current['expires'] ?? 0) >= time()
            && (($current['consumed'] ?? false) !== true || $allowConsumed)) {
            return (string) $current['request'];
        }
        $request = self::uuidV4();
        $all[$key] = ['request' => $request, 'expires' => time() + 900, 'consumed' => false];
        $_SESSION['bladecp_fast_deploy_create'] = $all;
        return $request;
    }

    /** Records durable creation before commercial follow-up can fail. */
    private static function markCreateNonceConsumed(FastDeployGateway $gateway, array $identity, string $request): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION) || !self::uuid($request)) { return; }
        $key = implode(':', [$gateway->serverId(), $identity['clientId']]);
        $all = $_SESSION['bladecp_fast_deploy_create'] ?? [];
        if (!is_array($all) || !is_array($all[$key] ?? null)
            || !hash_equals((string) ($all[$key]['request'] ?? ''), $request)) { return; }
        $all[$key]['consumed'] = true;
        $_SESSION['bladecp_fast_deploy_create'] = $all;
    }

    private static function actionKey(FastDeployGateway $gateway, array $identity, string $request, string $operation, array $payload, ?string $operationId = null): ?string
    {
        if ($operationId !== null) {
            return 'whmcs-fast-deploy-' . $gateway->serverId() . '-' . $identity['clientId']
                . '-' . $request . '-' . $operation . '-' . $operationId;
        }
        if (!isset($_SESSION) || !is_array($_SESSION)) { return null; }
        try {
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\Throwable) { return null; }
        $key = implode(':', [$gateway->serverId(), $identity['clientId'], $request, $operation, $fingerprint]);
        $all = $_SESSION['bladecp_fast_deploy_actions'] ?? [];
        if (!is_array($all)) { $all = []; }
        $current = $all[$key] ?? null;
        if (is_array($current) && is_string($current['key'] ?? null) && (int) ($current['expires'] ?? 0) >= time()) {
            return $current['key'];
        }
        $value = 'whmcs-fast-deploy-' . $identity['clientId'] . '-' . $request . '-' . $operation . '-' . bin2hex(random_bytes(12));
        $all[$key] = ['key' => $value, 'expires' => time() + 900];
        $_SESSION['bladecp_fast_deploy_actions'] = $all;
        return $value;
    }

    /** Adds a local invoice URL only after proving its WHMCS ownership. */
    private static function present(FastDeployGateway $gateway, array $detail): array
    {
        $commercial = is_array($detail['commercial'] ?? null) ? $detail['commercial'] : null;
        if ($commercial === null) { return $detail; }
        $invoiceId = self::positiveId($commercial['invoiceId'] ?? null);
        $serviceId = self::positiveId($commercial['externalServiceId'] ?? null);
        if ($invoiceId !== null && $serviceId !== null && self::ownsInvoice($invoiceId, $serviceId, $gateway)) {
            $base = VibePromptCard::basePath((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''));
            $commercial['invoiceUrl'] = $base . '/viewinvoice.php?id=' . $invoiceId;
        } else {
            unset($commercial['invoiceUrl']);
        }
        $detail['commercial'] = $commercial;
        return $detail;
    }

    private static function ownsInvoice(int $invoiceId, int $serviceId, FastDeployGateway $gateway): bool
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) { return false; }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            foreach (['tblinvoices', 'tblinvoiceitems', 'tblhosting'] as $table) {
                if (!$schema->hasTable($table)) { return false; }
            }
            $invoiceColumns = array_flip($schema->getColumnListing('tblinvoices'));
            $itemColumns = array_flip($schema->getColumnListing('tblinvoiceitems'));
            $serviceColumns = array_flip($schema->getColumnListing('tblhosting'));
            if (!isset($invoiceColumns['userid'], $itemColumns['invoiceid'], $itemColumns['relid'], $itemColumns['type']
                , $serviceColumns['userid'], $serviceColumns['server'])) { return false; }
            $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
            $service = \WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
            if ($invoice === null || $service === null || (int) ($invoice->userid ?? 0) !== $gateway->clientId()
                || (int) ($service->userid ?? 0) !== $gateway->clientId()
                || (int) ($service->server ?? 0) !== $gateway->serverId()) { return false; }
            return \WHMCS\Database\Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)
                ->where('relid', $serviceId)->where('type', 'Hosting')->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function positiveId(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    private static function prepareJsonInput(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') { return true; }
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_starts_with($contentType, 'application/json')) { return true; }
        $length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if ($length === false || $length < 0 || $length > 1048576) { return false; }
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        if (!is_string($raw) || strlen($raw) > 1048576) { return false; }
        try { $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return false; }
        if (!is_array($decoded)) { return false; }
        $_POST = $decoded;
        return true;
    }
}
