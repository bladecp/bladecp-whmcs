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

/** Suspends a Vibe account while preserving hosting and retained data. */
final class SuspendAccount
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
            return 'BladeCP: não foi possível confirmar o estado comercial do Vibe antes da suspensão.';
        }
        if ($deferred) {
            $native = VibeDeferredActivation::nativeService($params);
            $planUuid = VibePlanReference::fromParams($params);
            $tier = VibePlanTier::fromParams($params);
            if ($native === null || $planUuid === null || $tier === null) {
                return 'BladeCP: não foi possível confirmar o serviço Vibe antes da suspensão.';
            }
            $remote = VibeDeferredActivation::externalAccount($client, $native['serviceId']);
            if ($remote === false) {
                return 'BladeCP: não foi possível confirmar o vínculo comercial do Vibe antes da suspensão.';
            }
            if ($remote !== null && !hash_equals($planUuid, $remote['planUuid'])) {
                return 'BladeCP: o vínculo comercial existente do Vibe precisa ser revisado pelo suporte.';
            }
            // Persist the local deny marker before WHMCS can mark the service
            // suspended, including the case where identity was never linked.
            if (!VibeDeferredActivation::persist($params, $native['serviceId'], $tier, VibeDeferredActivation::ACCESS_SUSPENDED)) {
                return 'BladeCP: não foi possível registrar a suspensão comercial do Vibe.';
            }
            if ($remote === null) {
                Logger::logCall('VibeSuspendAccountDeferred', ['externalServiceId' => $native['serviceId']], ['status' => 'deferred_identity']);
                return 'success';
            }
            $accountId = $remote['accountId'];
        }
        if ($accountId === null) {
            return 'BladeCP: Vibe Account ID ausente.';
        }

        $transition = LifecycleTransition::resolve(
            $client,
            $accountId,
            $params['serviceid'] ?? null,
            'suspend',
            'suspended'
        );
        if ($transition === null) {
            Logger::logCall('VibeSuspendAccount', ['accountId' => $accountId], [
                'error' => 'lifecycle_snapshot_unavailable',
            ]);
            return 'BladeCP: não foi possível confirmar o estado atual da conta Vibe antes da suspensão.';
        }
        if ($transition['complete']) {
            Logger::logCall('VibeSuspendAccount', ['accountId' => $accountId], $transition['snapshot']);
            return 'success';
        }

        $response = $client->post(
            '/vibe/accounts/' . rawurlencode($accountId) . '/suspend',
            [],
            ['Idempotency-Key' => (string) $transition['idempotencyKey']]
        );
        if (!AccountResponse::lifecycle($response, $accountId, ['suspended'])) {
            Logger::logCall('VibeSuspendAccount', ['accountId' => $accountId], $response);
            return 'BladeCP: a API não confirmou semanticamente a suspensão da conta Vibe.';
        }

        Logger::logCall('VibeSuspendAccount', ['accountId' => $accountId], $response);
        return 'success';
    }

    private static function accountId(array $params): ?string
    {
        $accountId = ServiceProperties::get($params, 'BladeCP Vibe Account ID');
        return is_string($accountId) && trim($accountId) !== ''
            ? trim($accountId)
            : null;
    }
}
