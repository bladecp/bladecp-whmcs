<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceFields;
use WHMCS\Module\Server\Bladecp\Helpers\VibeUpgradeGuard;
use WHMCS\Module\Server\Bladecp\Helpers\CommercialUpgradeResume;
use WHMCS\Module\Server\Bladecp\Helpers\VibeDeferredActivation;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceOperationLock;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Changes the authoritative plan of an existing Vibe account. */
final class ChangePackage
{
    public static function run(array $params): string
    {
        $currentTier = VibePlanTier::normalize(ServiceProperties::get($params, 'BladeCP Vibe Plan Tier'));
        $destinationTier = VibePlanTier::fromParams($params);
        if (VibePlanTier::isForbiddenDowngrade($currentTier, $destinationTier)) {
            return 'BladeCP: o downgrade do Vibe Pro para Free não está disponível.';
        }
        $release = VibeServiceOperationLock::acquire((int) ($params['serviceid'] ?? 0));
        if ($release === null) {
            return 'BladeCP: o serviço Vibe está sendo atualizado. Tente novamente em instantes.';
        }
        try {
            return self::execute($params);
        } finally {
            $release();
        }
    }

    private static function execute(array $params): string
    {
        $accountId = trim((string) ServiceProperties::get($params, 'BladeCP Vibe Account ID', ''));
        $planUuid = VibePlanReference::fromParams($params);
        if ($planUuid === null) {
            return 'BladeCP: selecione um plano Vibe válido antes de alterar o pacote.';
        }
        $serviceId = trim((string) ($params['serviceid'] ?? ''));
        if ($serviceId === '') {
            return 'BladeCP: o identificador do serviço WHMCS é obrigatório para alterar o Vibe.';
        }
        $currentTier = VibePlanTier::normalize(ServiceProperties::get($params, 'BladeCP Vibe Plan Tier'));
        if ($currentTier === null) {
            $currentTier = VibeUpgradeGuard::currentTierForService((int) $serviceId);
        }
        $destinationTier = VibePlanTier::fromParams($params);
        if (VibePlanTier::isForbiddenDowngrade($currentTier, $destinationTier)) {
            return 'BladeCP: o downgrade do Vibe Pro para Free não está disponível.';
        }

        $client = Client::fromParams($params);
        $deferred = VibeDeferredActivation::deferredForService($params);
        if ($deferred === null) {
            return 'BladeCP: não foi possível confirmar o estado comercial do Vibe antes da alteração do plano.';
        }
        if ($deferred) {
            $native = VibeDeferredActivation::nativeService($params);
            $federation = VibeDeferredActivation::federation($params);
            if ($native === null || in_array($native['status'], ['cancelled', 'terminated', 'fraud'], true)
                || $federation === null || $destinationTier === null
                || !VibeDeferredActivation::ready($client, $federation['installationId'], $planUuid)) {
                return 'BladeCP: a configuração comercial do Vibe não está pronta para alterar o plano.';
            }
            $remote = VibeDeferredActivation::externalAccount($client, $native['serviceId']);
            if ($remote === false) {
                return 'BladeCP: não foi possível confirmar o vínculo comercial do Vibe antes da alteração do plano.';
            }
            if ($remote === null) {
                $accessState = VibeDeferredActivation::accessStateForService($native['serviceId']);
                if ($accessState === null) {
                    return 'BladeCP: não foi possível confirmar o estado de acesso do Vibe antes da alteração do plano.';
                }
                if ($accessState === '') {
                    $accessState = $native['status'] === 'suspended'
                        ? VibeDeferredActivation::ACCESS_SUSPENDED
                        : VibeDeferredActivation::ACCESS_ACTIVE;
                }
                if (!VibeDeferredActivation::persist($params, $native['serviceId'], $destinationTier, $accessState)) {
                    return 'BladeCP: não foi possível registrar a alteração comercial do plano Vibe.';
                }
                Logger::logCall('VibeChangePackageDeferred', [
                    'externalServiceId' => $native['serviceId'], 'planUuid' => $planUuid,
                ], ['status' => 'deferred_identity']);
                return 'success';
            }
            $accountId = $remote['accountId'];
        }
        if ($accountId === '') {
            return 'BladeCP: identificador da conta Vibe ausente.';
        }

        $body = ['planUuid' => $planUuid];
        $response = $client->patch(
            '/vibe/accounts/' . rawurlencode($accountId),
            $body,
            ['Idempotency-Key' => 'whmcs-' . $serviceId . '-vibe-changepackage-' . hash('sha256', $planUuid)]
        );
        if (!self::isMeaningfulAccountResponse($response, $accountId, $planUuid)) {
            Logger::logCall('VibeChangePackage', ['accountId' => $accountId] + $body, $response);
            return 'BladeCP: a API não retornou todos os dados necessários para confirmar a alteração do plano Vibe. Nenhuma sincronização local foi gravada.';
        }

        $properties = ['Last Sync' => date('Y-m-d H:i:s')];
        if ($destinationTier !== null) {
            $properties['BladeCP Vibe Plan Tier'] = $destinationTier;
        }
        ServiceProperties::save($params, $properties);
        if ($destinationTier !== null && !VibeServiceFields::persist($params, [
            'BladeCP Vibe Plan Tier' => $destinationTier,
        ])) {
            return 'BladeCP: o plano foi confirmado, mas não foi possível persistir o nível comercial da conta Vibe no serviço WHMCS.';
        }
        Logger::logCall('VibeChangePackage', ['accountId' => $accountId] + $body, $response);
        // A commercial resume is only consumed after WHMCS has successfully
        // accepted and the Customer API has confirmed the package change.
        CommercialUpgradeResume::clear();

        return 'success';
    }

    /** @param array<string,mixed> $response */
    private static function isMeaningfulAccountResponse(
        array $response,
        string $accountId,
        string $planUuid
    ): bool {
        return hash_equals($accountId, trim((string) ($response['accountId'] ?? '')))
            && hash_equals($planUuid, trim((string) ($response['planUuid'] ?? '')))
            && in_array(trim((string) ($response['aiMode'] ?? '')), ['partner_ai', 'managed_ai'], true)
            && in_array(trim((string) ($response['status'] ?? '')), ['pending', 'active', 'suspended'], true)
            && self::positiveInteger($response['entitlementVersion'] ?? null);
    }

    private static function positiveInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }
}
