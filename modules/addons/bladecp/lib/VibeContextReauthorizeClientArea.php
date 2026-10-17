<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Helpers\VibeContextRecoveryResume;
use WHMCS\Module\Server\Bladecp\Helpers\VibeContextRecoveryService;
use WHMCS\Module\Server\Bladecp\Helpers\VibeSsoDestination;
use WHMCS\Module\Server\Bladecp\Ui\PartnerBrand;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Fixed, owner-authenticated return point for stale direct Vibe contexts. */
final class VibeContextReauthorizeClientArea
{
    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars): array
    {
        self::protectResponse();
        VibeContextRecoveryResume::rememberTicketFromRequest();
        // No query value may become recovery authority after this boundary.
        foreach (array_keys($_GET) as $key) {
            if ($key !== 'm' && $key !== 'action') {
                unset($_GET[$key]);
            }
        }

        $server = AdminServerContext::resolve();
        $serverId = ($server['state'] ?? '') === 'configured'
            ? filter_var($server['serverId'] ?? null, FILTER_VALIDATE_INT)
            : false;
        $client = $serverId === false ? null : ServerConfig::clientForServerId((int) $serverId);
        $identity = IdentityHandoff::currentIdentityDecision();
        if (($identity['status'] ?? '') !== 'ready' || !is_array($identity['identity'] ?? null)) {
            return self::view('', self::identityError((string) ($identity['status'] ?? '')), $client);
        }
        $ticket = VibeContextRecoveryResume::consumeTicket();
        if ($ticket === null || $client === null || $serverId === false) {
            return self::view('', ClientCopy::text('client-flows', 'access_unconfirmed'), $client);
        }
        $userId = (int) ($identity['identity']['userId'] ?? 0);
        $clientId = (int) ($identity['identity']['clientId'] ?? 0);
        if ($userId <= 0 || $clientId <= 0) {
            return self::view('', ClientCopy::text('client-flows', 'access_unconfirmed'), $client);
        }

        try {
            $subject = 'whmcs-user:' . $userId;
            $account = 'whmcs-client:' . $clientId;
            $recovery = $client->redeemVibeContextRecoveryTicket($ticket, $subject, $account);
            $service = VibeContextRecoveryService::resolve(
                $clientId,
                $recovery['externalServiceId'],
                (int) $serverId,
                $client,
                $recovery['accountId']
            );
            if ($service === null) {
                return self::view('', ClientCopy::text('client-flows', 'access_unconfirmed'), $client);
            }
            $confirmation = $client->confirmVibeContextRecovery($recovery['recoveryId'], $subject, $account);
            if (!VibeSsoDestination::isContextRecoveryCallback($confirmation['callbackUrl'], $client->baseUrl())
                || !VibeContextRecoveryService::persistBindingVersion(
                    $service['serviceId'], $service['productId'], $recovery['bindingVersion']
                )) {
                return self::view('', ClientCopy::text('client-flows', 'access_unconfirmed'), $client);
            }
            return self::view(
                $confirmation['callbackUrl'] . '?code=' . rawurlencode($confirmation['resultCode']),
                '',
                $client
            );
        } catch (\Throwable) {
            return self::view('', ClientCopy::text('client-flows', 'access_unconfirmed'), $client);
        }
    }

    /** @return array<string,mixed> */
    private static function view(string $redirectTo, string $error, ?Client $client): array
    {
        return [
            'pagetitle' => 'BladeCP Vibe',
            'breadcrumb' => ['index.php?m=bladecp&action=vibe-context-reauthorize' => 'BladeCP Vibe'],
            'templatefile' => 'vibe-context-reauthorize',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'redirectTo' => $redirectTo,
                'contextRecoveryError' => $error,
                'partnerBrandLogoHtml' => PartnerBrand::logoHtml(PartnerBrand::fromClient($client)),
            ],
        ];
    }

    private static function identityError(string $status): string
    {
        return match ($status) {
            'masquerading_admin' => ClientCopy::text('client-flows', 'confirmation_admin_blocked'),
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
