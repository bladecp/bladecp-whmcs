<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\CommercialUpgradeResume;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Converts the server-attested offer into a native WHMCS upgrade POST. */
final class CommercialUpgradeClientArea
{
    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public static function render(array $vars): array
    {
        self::protectResponse();
        $resume = CommercialUpgradeResume::stateForCurrentClientWithFailure();
        $state = $resume['state'];
        $error = self::resumeError($resume['failure']);
        if ($state !== null) {
            $state = CommercialUpgradeResume::beginBridgeForCurrentClient();
            if ($state === null) {
                $error = ClientCopy::text('client-flows', 'offer_in_progress');
            }
        }
        return [
            'pagetitle' => 'BladeCP Vibe',
            'breadcrumb' => ['index.php?m=bladecp&action=commercial-upgrade' => 'BladeCP Vibe'],
            'templatefile' => 'commercial-upgrade',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'commercialUpgradeError' => $error,
                'serviceId' => $state['serviceId'] ?? 0,
                'targetProductId' => $state['productId'] ?? 0,
                'billingCycle' => $state['billingCycle'] ?? '',
                'bridgeNonce' => $state['nonce'] ?? '',
            ],
        ];
    }

    /** @param 'missing'|'expired'|'invalid'|'client_session_mismatch'|null $failure */
    private static function resumeError(?string $failure): string
    {
        return match ($failure) {
            'missing' => ClientCopy::text('client-flows', 'offer_missing'),
            'expired' => ClientCopy::text('client-flows', 'offer_expired'),
            'client_session_mismatch' => ClientCopy::text('client-flows', 'offer_session_mismatch'),
            'invalid' => ClientCopy::text('client-flows', 'offer_invalid'),
            default => '',
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
