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

/** Reactivates a suspended Vibe account when its entitlement is valid. */
final class UnsuspendAccount
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
            return 'BladeCP: não foi possível confirmar o estado comercial do Vibe antes da reativação.';
        }
        if ($deferred) {
            $native = VibeDeferredActivation::nativeService($params);
            $planUuid = VibePlanReference::fromParams($params);
            $tier = VibePlanTier::fromParams($params);
            $federation = VibeDeferredActivation::federation($params);
            if ($native === null || in_array($native['status'], ['cancelled', 'terminated', 'fraud'], true)
                || $planUuid === null || $tier === null || $federation === null
                || !VibeDeferredActivation::ready($client, $federation['installationId'], $planUuid)) {
                return 'BladeCP: a configuração comercial do Vibe não está pronta para reativação.';
            }
            $remote = VibeDeferredActivation::externalAccount($client, $native['serviceId']);
            if ($remote === false) {
                return 'BladeCP: não foi possível confirmar o vínculo comercial do Vibe antes da reativação.';
            }
            if ($remote !== null && !hash_equals($planUuid, $remote['planUuid'])) {
                return 'BladeCP: o vínculo comercial existente do Vibe precisa ser revisado pelo suporte.';
            }
            if ($remote === null) {
                if (!VibeDeferredActivation::persist($params, $native['serviceId'], $tier, VibeDeferredActivation::ACCESS_ACTIVE)) {
                    return 'BladeCP: não foi possível registrar a reativação comercial do Vibe.';
                }
                Logger::logCall('VibeUnsuspendAccountDeferred', ['externalServiceId' => $native['serviceId']], ['status' => 'deferred_identity']);
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
            'reactivate',
            'active'
        );
        if ($transition === null) {
            Logger::logCall('VibeUnsuspendAccount', ['accountId' => $accountId], [
                'error' => 'lifecycle_snapshot_unavailable',
            ]);
            return 'BladeCP: não foi possível confirmar o estado atual da conta Vibe antes da reativação.';
        }
        if ($transition['complete']) {
            if ($deferred) {
                $tier = VibePlanTier::fromParams($params);
                $serviceId = (int) ($params['serviceid'] ?? 0);
                if ($tier === null || $serviceId <= 0
                    || !VibeDeferredActivation::persist($params, $serviceId, $tier, VibeDeferredActivation::ACCESS_ACTIVE)) {
                    return 'BladeCP: a conta Vibe está ativa, mas não foi possível registrar a reativação comercial.';
                }
            }
            Logger::logCall('VibeUnsuspendAccount', ['accountId' => $accountId], $transition['snapshot']);
            return 'success';
        }

        $response = $client->post(
            '/vibe/accounts/' . rawurlencode($accountId) . '/reactivate',
            [],
            ['Idempotency-Key' => (string) $transition['idempotencyKey']]
        );
        if (!AccountResponse::lifecycle($response, $accountId, ['active'])) {
            Logger::logCall('VibeUnsuspendAccount', ['accountId' => $accountId], $response);
            return 'BladeCP: a API não confirmou semanticamente a reativação da conta Vibe.';
        }

        Logger::logCall('VibeUnsuspendAccount', ['accountId' => $accountId], $response);
        if ($deferred) {
            $tier = VibePlanTier::fromParams($params);
            $serviceId = (int) ($params['serviceid'] ?? 0);
            if ($tier === null || $serviceId <= 0
                || !VibeDeferredActivation::persist($params, $serviceId, $tier, VibeDeferredActivation::ACCESS_ACTIVE)) {
                return 'BladeCP: a conta Vibe foi reativada, mas não foi possível registrar o acesso comercial.';
            }
        }
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
