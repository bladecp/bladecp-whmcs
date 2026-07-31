<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Classifica planos recebidos da API para uso pelo módulo. */
final class PlanCatalog
{
    private const MODULE_TYPES = [
        'stack-container',
        'edge-cloud',
        'dedicated',
    ];

    /**
     * @return array{
     *   type:string,
     *   supported:bool,
     *   reason:?string,
     *   activationMode:string,
     *   requiresContract:bool,
     *   supportsContractTerms:bool,
     *   contractTerms:array
     * }
     */
    public static function classify(array $plan): array
    {
        $type = strtolower(trim((string) (
            $plan['customerApiProductType'] ?? ''
        )));
        $apiSupported = ($plan['customerApiProvisioningSupported'] ?? null) === true
            || ($plan['customerApiProvisioningSupported'] ?? null) === 1;
        $activationMode = strtolower(trim((string) (
            $plan['activationMode'] ?? 'auto'
        )));
        $requiresContract = ($plan['requiresContract'] ?? false) === true
            || ($plan['requiresContract'] ?? false) === 1;
        $supportsContractTerms =
            ($plan['supportsContractTerms'] ?? false) === true
            || ($plan['supportsContractTerms'] ?? false) === 1;
        $contractTerms = is_array($plan['contractTerms'] ?? null)
            ? array_values($plan['contractTerms'])
            : [];

        $availability = PlanAvailability::fromPlan($plan);
        $reason = null;
        if ($type === '') {
            $reason = 'O catálogo não informou o tipo canônico do produto.';
        } elseif (!in_array($type, self::MODULE_TYPES, true)) {
            $reason = "O tipo '{$type}' ainda não possui handler neste módulo.";
        } elseif (!$apiSupported) {
            $reason = 'A Customer API marcou o plano como indisponível para provisionamento.';
        } elseif ($activationMode !== 'auto' && $activationMode !== 'manual') {
            $reason = 'O modo de ativação do plano é desconhecido.';
        } elseif ($requiresContract && (!$supportsContractTerms || $contractTerms === [])) {
            $reason = 'O plano exige contrato, mas não publicou termos selecionáveis.';
        }

        return [
            'type' => $type,
            'supported' => $reason === null,
            'reason' => $reason,
            'activationMode' => $activationMode,
            'requiresContract' => $requiresContract,
            'supportsContractTerms' => $supportsContractTerms,
            'contractTerms' => $contractTerms,
            'availability' => $availability,
            'salesEligible' => $availability['salesEligible'],
            'availabilityKnown' => $availability['known'],
            'legacyAvailability' => $availability['legacy'],
        ];
    }
}
