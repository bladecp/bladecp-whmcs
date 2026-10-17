<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibeDeferredActivation;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Terminates a Vibe account; the API retains its data for the approved period. */
final class TerminateAccount
{
    public static function run(array $params): string
    {
        $release = \WHMCS\Module\Server\Bladecp\Helpers\VibeServiceOperationLock::acquire((int) ($params['serviceid'] ?? 0));
        if ($release === null) { return 'BladeCP: o serviço Vibe está sendo atualizado. Tente novamente em instantes.'; }
        try { return self::execute($params); } finally { $release(); }
    }

    private static function execute(array $params): string
    {
        $accountId = self::accountId($params);
        $client = Client::fromParams($params);
        $deferred = VibeDeferredActivation::deferredForService($params);
        if ($deferred === null) {
            return 'BladeCP: não foi possível confirmar o estado comercial do Vibe antes do encerramento.';
        }
        if ($deferred) {
            $native = VibeDeferredActivation::nativeService($params);
            $planUuid = VibePlanReference::fromParams($params);
            $tier = VibePlanTier::fromParams($params);
            if ($native === null || $planUuid === null || $tier === null) {
                return 'BladeCP: não foi possível confirmar o serviço Vibe antes do encerramento.';
            }
            $remote = VibeDeferredActivation::externalAccount($client, $native['serviceId']);
            if ($remote === false) {
                return 'BladeCP: não foi possível confirmar o vínculo comercial do Vibe antes do encerramento.';
            }
            if ($remote !== null && !hash_equals($planUuid, $remote['planUuid'])) {
                return 'BladeCP: o vínculo comercial existente do Vibe precisa ser revisado pelo suporte.';
            }
            // Do not leave an active access marker while WHMCS advances the
            // service to Terminated, even when there is no linked identity.
            if (!VibeDeferredActivation::persist($params, $native['serviceId'], $tier, VibeDeferredActivation::ACCESS_TERMINATED)) {
                return 'BladeCP: não foi possível registrar o encerramento comercial do Vibe.';
            }
            if ($remote === null) {
                Logger::logCall('VibeTerminateAccountDeferred', ['externalServiceId' => $native['serviceId']], ['status' => 'deferred_identity']);
                return 'success';
            }
            $accountId = $remote['accountId'];
        }
        if ($accountId === null) {
            return 'BladeCP: Vibe Account ID ausente.';
        }

        $response = $client->delete(
            '/vibe/accounts/' . rawurlencode($accountId),
            [],
            ['Idempotency-Key' => self::idempotencyKey($params)]
        );
        if (!AccountResponse::lifecycle($response, $accountId, ['terminated'])) {
            Logger::logCall('VibeTerminateAccount', ['accountId' => $accountId], $response);
            return 'BladeCP: a API não confirmou semanticamente o encerramento da conta Vibe.';
        }

        Logger::logCall('VibeTerminateAccount', ['accountId' => $accountId], $response);
        return 'success';
    }

    private static function accountId(array $params): ?string
    {
        $accountId = ServiceProperties::get($params, 'BladeCP Vibe Account ID');
        return is_string($accountId) && trim($accountId) !== ''
            ? trim($accountId)
            : null;
    }

    private static function idempotencyKey(array $params): string
    {
        return 'whmcs-' . ($params['serviceid'] ?? '0') . '-vibe-terminate';
    }
}
