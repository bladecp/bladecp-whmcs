<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\CommercialActionResume;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceFields;
use WHMCS\Module\Server\Bladecp\Helpers\CommercialUpgradeResume;
use WHMCS\Module\Server\Bladecp\Helpers\CommercialRuntimeIntegrity;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Addon\Bladecp\Provisioning;
use WHMCS\Module\Server\Bladecp\Ui\PartnerBrand;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Redeems a partner-issued commercial ticket only after proving the local
 * WHMCS owner. The API attests the service after ticket validation and the
 * addon then revalidates that exact active Vibe service. Browser query values
 * never decide the client, service, action or destination.
 */
final class CommercialActionClientArea
{
    private const ACTIVE_STATUSES = ['active'];

    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars): array
    {
        self::protectResponse();
        if (!CommercialRuntimeIntegrity::ready()) {
            return self::view('', ClientCopy::text('client-flows', 'commercial_integration_outdated'));
        }
        CommercialActionResume::rememberTicketFromRequest();
        // The ticket has already been copied to the server session. Remove it
        // before template rendering so it cannot enter DOM, history helpers or
        // accidental application logs below this boundary.
        unset($_GET['ticket']);

        $identity = IdentityHandoff::currentIdentityDecision();
        if (($identity['status'] ?? '') !== 'ready' || !is_array($identity['identity'] ?? null)) {
            return self::view('', self::identityError((string) ($identity['status'] ?? '')));
        }

        $ticket = CommercialActionResume::consumeTicket();
        if ($ticket === null) {
            return self::view('', ClientCopy::text('client-flows', 'commercial_access_expired'));
        }

        $server = AdminServerContext::resolve();
        $serverId = ($server['state'] ?? '') === 'configured'
            ? filter_var($server['serverId'] ?? null, FILTER_VALIDATE_INT)
            : false;
        $client = $serverId === false ? null : ServerConfig::clientForServerId((int) $serverId);
        if ($client === null || $serverId === false) {
            return self::view('', ClientCopy::text('client-flows', 'commercial_integration_unconfirmed'));
        }

        try {
            $response = $client->redeemVibeCommercialActionTicket(
                $ticket,
                'whmcs-user:' . $identity['identity']['userId'],
                'whmcs-client:' . $identity['identity']['clientId']
            );
        } catch (\Throwable) {
            return self::view('', ClientCopy::text('client-flows', 'commercial_action_unopened'));
        }

        $serviceId = filter_var($response['externalServiceId'] ?? null, FILTER_VALIDATE_INT);
        $context = $serviceId === false ? null : self::resolveServiceContext(
            (int) $identity['identity']['clientId'],
            (int) $serviceId,
            (int) $serverId,
            $client,
            $response
        );
        $destination = $context === null ? null : self::destination($response, $context);
        if ($destination === null) {
            return self::view('', ClientCopy::text('client-flows', 'commercial_action_unavailable'));
        }

        return self::view($destination, '', $client);
    }

    /** @return array<string,mixed> */
    private static function view(string $redirectTo, string $error, ?Client $client = null): array
    {
        return [
            'pagetitle' => 'BladeCP Vibe',
            'breadcrumb' => ['index.php?m=bladecp&action=commercial-handoff' => 'BladeCP Vibe'],
            'templatefile' => 'commercial-handoff',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'redirectTo' => $redirectTo,
                'commercialActionError' => $error,
                'partnerBrandLogoHtml' => PartnerBrand::logoHtml(
                    PartnerBrand::fromClient($client ?? ServerConfig::client())
                ),
            ],
        ];
    }

    /**
     * @return array{serviceId:int,clientId:int,accountId:string,bindingVersion:int,serverId:int,client:Client}|null
     */
    private static function resolveServiceContext(
        int $clientId,
        int $serviceId,
        int $redeemServerId,
        Client $redeemClient,
        array $response
    ): ?array
    {
        if ($clientId <= 0 || $serviceId <= 0 || $redeemServerId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            foreach (['tblhosting', 'tblproducts', 'tblcustomfields', 'tblcustomfieldsvalues'] as $table) {
                if (!$schema->hasTable($table)) {
                    return null;
                }
            }
            $hostingColumns = array_flip($schema->getColumnListing('tblhosting'));
            foreach (['id', 'userid', 'packageid', 'server', 'domainstatus'] as $column) {
                if (!isset($hostingColumns[$column])) {
                    return null;
                }
            }

            $service = \WHMCS\Database\Capsule::table('tblhosting')
                ->where('id', $serviceId)->where('userid', $clientId)->first();
            if ($service === null || !in_array(strtolower(trim((string) ($service->domainstatus ?? ''))), self::ACTIVE_STATUSES, true)) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', (int) ($service->packageid ?? 0))->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return null;
            }
            $accountId = self::customFieldValue($serviceId, (int) $product->id, 'BladeCP Vibe Account ID');
            $serverId = filter_var($service->server ?? null, FILTER_VALIDATE_INT);
            $serviceClient = $serverId !== false ? ServerConfig::clientForServerId((int) $serverId) : null;
            if ((int) ($service->server ?? 0) !== $redeemServerId
                || $accountId === null || $serviceClient === null
                || !hash_equals($redeemClient->baseUrl(), $serviceClient->baseUrl())) {
                return null;
            }

            $storedBindingVersion = self::customFieldValue(
                $serviceId,
                (int) $product->id,
                'BladeCP Identity Binding Version'
            );
            $bindingVersion = self::attestedBindingVersion(
                $response,
                $serviceId,
                $accountId,
                $storedBindingVersion
            );
            if ($bindingVersion === null) {
                return null;
            }
            if ($storedBindingVersion === null && !VibeServiceFields::persist([
                'serviceid' => $serviceId,
                'pid' => (int) $product->id,
            ], [
                'BladeCP Identity Binding Version' => (string) $bindingVersion,
            ])) {
                return null;
            }
            return [
                'serviceId' => $serviceId,
                'clientId' => $clientId,
                'accountId' => $accountId,
                'bindingVersion' => (int) $bindingVersion,
                'serverId' => (int) $serverId,
                'client' => $serviceClient,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $response */
    private static function attestedBindingVersion(
        array $response,
        int $serviceId,
        string $accountId,
        ?string $storedBindingVersion
    ): ?int {
        $attestedServiceId = trim((string) ($response['externalServiceId'] ?? ''));
        $attestedAccountId = trim((string) ($response['accountId'] ?? ''));
        $attestedVersion = filter_var($response['bindingVersion'] ?? null, FILTER_VALIDATE_INT);
        if ($attestedVersion === false || (int) $attestedVersion <= 0
            || !hash_equals((string) $serviceId, $attestedServiceId)
            || !hash_equals($accountId, $attestedAccountId)) {
            return null;
        }

        if ($storedBindingVersion === null) {
            return (int) $attestedVersion;
        }
        $storedVersion = filter_var($storedBindingVersion, FILTER_VALIDATE_INT);
        return $storedVersion !== false && (int) $storedVersion === (int) $attestedVersion
            ? (int) $storedVersion
            : null;
    }

    private static function customFieldValue(int $serviceId, int $productId, string $fieldName): ?string
    {
        if ($serviceId <= 0 || $productId <= 0) {
            return null;
        }
        $field = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'product')->where('relid', $productId)->where('fieldname', $fieldName)->first();
        $value = $field === null ? null : \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', (int) ($field->id ?? 0))->where('relid', $serviceId)->first();
        $result = trim((string) ($value->value ?? ''));
        return $result === '' ? null : $result;
    }

    /** @param array<string,mixed> $response @param array{serviceId:int,clientId:int,accountId:string,bindingVersion:int,serverId:int,client:Client} $context */
    private static function destination(array $response, array $context): ?string
    {
        $action = trim((string) ($response['action'] ?? ''));
        $service = trim((string) ($response['externalServiceId'] ?? ''));
        $account = trim((string) ($response['accountId'] ?? ''));
        $version = filter_var($response['bindingVersion'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($action, ['portal', 'upgrade', 'add_credits', 'support'], true)
            || !hash_equals((string) $context['serviceId'], $service)
            || !hash_equals($context['accountId'], $account)
            || $version === false || (int) $version !== $context['bindingVersion']) {
            return null;
        }

        if ($action === 'upgrade' && !self::rememberUpgradeTarget($response, $context)) {
            return null;
        }
        return match ($action) {
            'portal' => 'clientarea.php?action=productdetails&id=' . rawurlencode((string) $context['serviceId']),
            'upgrade' => 'index.php?m=bladecp&action=commercial-upgrade',
            'support' => self::supportDestination($response),
            'add_credits' => self::creditsDestination($response),
        };
    }

    /** @param array<string,mixed> $response @param array{serviceId:int,clientId:int,accountId:string,bindingVersion:int,serverId:int,client:Client} $context */
    private static function rememberUpgradeTarget(array $response, array $context): bool
    {
        $productId = filter_var($response['whmcsProductId'] ?? null, FILTER_VALIDATE_INT);
        $planUuid = trim((string) ($response['targetPlanUuid'] ?? ''));
        if ($productId === false || (int) $productId <= 0 || !self::isUuid($planUuid)) {
            return false;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', (int) $productId)->first();
            $groupId = Provisioning::serverGroup($context['serverId']);
            if ($product === null || $groupId <= 0 || !empty($product->hidden)
                || (int) ($product->servergroup ?? 0) !== $groupId
                || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe') {
                return false;
            }
            $reference = trim((string) ($product->configoption2 ?? ''));
            $mapped = self::isUuid($reference) ? $reference : VibePlanReference::fromCatalog(
                $reference,
                $context['client']->get('/partner/plans')
            );
            if (!hash_equals($planUuid, $mapped ?? '')) {
                return false;
            }
            $price = VibeCommercialCatalog::primaryPriceForProduct((int) $productId);
            return $price !== null && CommercialUpgradeResume::remember(
                $context['serviceId'], (int) $productId, $planUuid, $context['clientId'], $price['billingCycle']
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /** @param array<string,mixed> $response */
    private static function supportDestination(array $response): ?string
    {
        // The API may only choose this closed local instruction. A ticket must
        // never carry an arbitrary support URL into the browser.
        return ($response['instruction'] ?? null) === 'whmcs_support' ? 'submitticket.php' : null;
    }

    /** @param array<string,mixed> $response */
    private static function creditsDestination(array $response): ?string
    {
        if (($response['instruction'] ?? null) !== 'whmcs_vibe_credit_product') {
            return null;
        }
        $productId = filter_var($response['productId'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || (int) $productId <= 0) {
            return null;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', (int) $productId)->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp'
                || strtolower(trim((string) ($product->configoption1 ?? ''))) !== 'vibe-credit') {
                return null;
            }
            return 'cart.php?a=add&pid=' . rawurlencode((string) $productId);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function identityError(string $status): string
    {
        return match ($status) {
            'masquerading_admin' => ClientCopy::text('client-flows', 'action_admin_blocked'),
            'not_owner' => ClientCopy::text('client-flows', 'owner_only'),
            default => ClientCopy::text('client-flows', 'sign_in'),
        };
    }

    private static function protectResponse(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            header('Referrer-Policy: no-referrer');
        }
    }
}
