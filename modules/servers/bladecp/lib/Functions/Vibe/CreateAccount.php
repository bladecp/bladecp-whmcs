<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Vibe;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\ParamsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;
use WHMCS\Module\Server\Bladecp\Helpers\VibeEmailVerification;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanReference;
use WHMCS\Module\Server\Bladecp\Helpers\VibePlanTier;
use WHMCS\Module\Server\Bladecp\Helpers\VibeDeferredActivation;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceFields;
use WHMCS\Module\Server\Bladecp\Helpers\VibeServiceOperationLock;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Creates a Vibe account owned commercially by the authenticated partner. */
final class CreateAccount
{
    public static function run(array $params): string
    {
        $serviceId = trim((string) ($params['serviceid'] ?? ''));
        if (VibePlanReference::fromParams($params) === null) {
            return 'BladeCP: selecione um plano Vibe válido antes de provisionar.';
        }
        if ($serviceId === '') {
            return 'BladeCP: o identificador do serviço WHMCS é obrigatório para provisionar o Vibe.';
        }
        if (VibePlanTier::fromParams($params) === VibePlanTier::FREE) {
            $emailVerification = VibeEmailVerification::evaluate($params);
            if (($emailVerification['assurance'] ?? null) === null) {
                return !empty($emailVerification['confirmationRequired'])
                    ? 'BladeCP: o e-mail do cliente precisa estar confirmado no WHMCS antes de provisionar o Vibe Free.'
                    : 'BladeCP: não foi possível validar a política de confirmação de e-mail do WHMCS para provisionar o Vibe Free.';
            }
        }
        $release = VibeServiceOperationLock::acquire((int) $serviceId);
        if ($release === null) {
            return 'BladeCP: o serviço Vibe está sendo atualizado. Tente novamente em instantes.';
        }
        try {
            return self::execute($params, $serviceId);
        } finally {
            $release();
        }
    }

    private static function execute(array $params, string $serviceId): string
    {
        $planUuid = VibePlanReference::fromParams($params);
        if ($planUuid === null) {
            return 'BladeCP: selecione um plano Vibe válido antes de provisionar.';
        }
        $planTier = VibePlanTier::fromParams($params);
        $emailVerification = null;
        if ($planTier === VibePlanTier::FREE) {
            $emailVerification = VibeEmailVerification::evaluate($params);
            if (($emailVerification['assurance'] ?? null) === null) {
                return !empty($emailVerification['confirmationRequired'])
                    ? 'BladeCP: o e-mail do cliente precisa estar confirmado no WHMCS antes de provisionar o Vibe Free.'
                    : 'BladeCP: não foi possível validar a política de confirmação de e-mail do WHMCS para provisionar o Vibe Free.';
            }
        }

        if ($serviceId === '') {
            return 'BladeCP: o identificador do serviço WHMCS é obrigatório para provisionar o Vibe.';
        }

        $owner = ParamsMapper::buildOwner($params);
        $owner['email'] = trim($owner['email']);
        if ($owner['name'] === '' || filter_var($owner['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'BladeCP: os dados do cliente final precisam conter nome e e-mail válidos.';
        }

        $client = Client::fromParams($params);
        $federation = VibeDeferredActivation::federation($params);
        $native = VibeDeferredActivation::nativeService($params);
        if ($native === null || $native['serviceId'] !== (int) $serviceId
            || in_array(strtolower($native['status']), ['cancelled', 'terminated', 'fraud'], true)) {
            return 'BladeCP: não foi possível confirmar o serviço Vibe antes da ativação.';
        }
        $existing = VibeDeferredActivation::externalAccount($client, $native['serviceId']);
        if ($existing === false) {
            return 'BladeCP: não foi possível confirmar um vínculo comercial existente do Vibe. Tente novamente em alguns minutos.';
        }
        if ($existing !== null) {
            if (!hash_equals($planUuid, $existing['planUuid'])
                || !in_array(strtolower($existing['status']), ['pending', 'active', 'suspended'], true)) {
                return 'BladeCP: o vínculo comercial existente do Vibe precisa ser revisado pelo suporte.';
            }
            return self::persistExistingAccount($params, $existing, $planTier, $federation !== null);
        }
        if ($federation !== null) {
            if ($native['serverId'] !== $federation['serverId']) {
                return 'BladeCP: não foi possível confirmar o serviço Vibe antes da ativação.';
            }
            if (!VibeDeferredActivation::ready($client, $federation['installationId'], $planUuid)) {
                return 'BladeCP: a configuração comercial do Vibe não está pronta para ativação. Tente novamente em alguns minutos.';
            }
            if (!VibeDeferredActivation::persist($params, $native['serviceId'], $planTier ?? '', VibeDeferredActivation::ACCESS_ACTIVE)) {
                return 'BladeCP: não foi possível persistir a ativação comercial do Vibe no serviço WHMCS.';
            }
            Logger::logCall('VibeCreateAccountDeferred', [
                'externalServiceId' => $serviceId,
                'planUuid' => $planUuid,
                'installationId' => $federation['installationId'],
            ], ['status' => 'deferred_identity']);
            return 'success';
        }
        return 'BladeCP: a federação segura do Vibe precisa estar configurada neste servidor antes de ativar novas contas.';
    }

    /** @param array{accountId:string,customerUuid:string,partnerUuid:string,planUuid:string,externalServiceId:string,status:string} $account */
    private static function persistExistingAccount(array $params, array $account, ?string $tier, bool $deferred): string
    {
        $tier = $tier ?? '';
        $state = match (strtolower($account['status'])) {
            'active', 'pending' => VibeDeferredActivation::ACCESS_ACTIVE,
            'suspended' => VibeDeferredActivation::ACCESS_SUSPENDED,
            default => null,
        };
        if ($state === null || ($deferred && !VibeDeferredActivation::persist($params, (int) $account['externalServiceId'], $tier, $state))
            || !VibeServiceFields::persist($params, [
                'BladeCP Vibe Account ID' => $account['accountId'],
                'BladeCP Partner UUID' => $account['partnerUuid'],
                'BladeCP External Service ID' => $account['externalServiceId'],
                'BladeCP Vibe Plan Tier' => $tier,
            ])) {
            return 'BladeCP: não foi possível restaurar o vínculo da conta Vibe no serviço WHMCS.';
        }
        ServiceProperties::save($params, [
            'BladeCP Vibe Account ID' => $account['accountId'],
            'BladeCP Partner UUID' => $account['partnerUuid'],
            'Last Sync' => date('Y-m-d H:i:s'),
        ]);
        if ($state === VibeDeferredActivation::ACCESS_SUSPENDED) {
            return 'BladeCP: a conta Vibe vinculada está suspensa e precisa ser reativada antes da ativação comercial.';
        }
        return 'success';
    }

    /** @param array<string,mixed> $response */
    private static function isMeaningfulAccountResponse(array $response, string $planUuid): bool
    {
        $accountId = trim((string) ($response['accountId'] ?? ''));
        $customerUuid = trim((string) ($response['customerUuid'] ?? ''));
        $partnerUuid = trim((string) ($response['partnerUuid'] ?? ''));
        $responsePlan = trim((string) ($response['planUuid'] ?? ''));
        $aiMode = trim((string) ($response['aiMode'] ?? ''));
        $status = trim((string) ($response['status'] ?? ''));
        $activationMode = trim((string) ($response['activationMode'] ?? ''));

        return $accountId !== ''
            && $customerUuid !== ''
            && $partnerUuid !== ''
            && hash_equals($planUuid, $responsePlan)
            && in_array($aiMode, ['partner_ai', 'managed_ai'], true)
            && in_array($status, ['pending', 'active'], true)
            && $activationMode !== ''
            && self::positiveInteger($response['resourceVersion'] ?? null)
            && self::positiveInteger($response['entitlementVersion'] ?? null);
    }

    private static function positiveInteger(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0;
    }
}
