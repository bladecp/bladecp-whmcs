<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Normaliza as informações de disponibilidade recebidas no catálogo. */
final class PlanAvailability
{
    private const READY = 'ready';
    private const UNKNOWN = 'unknown';

    /**
     * @return array{
     *   known:bool,legacy:bool,schemaVersion:int,channel:string,
     *   channelSupported:bool,automaticProvisioningReady:bool,
     *   salesEligible:bool,locationRequirement:string,readiness:string,
     *   blockers:list<array{code:string,retryable:bool,action:string}>,
     *   checkedAt:?string,validUntil:?string
     * }
     */
    public static function fromPlan(array $plan): array
    {
        $raw = $plan['availability'] ?? null;
        if (!is_array($raw)) {
            return self::legacy($plan);
        }

        // The dedicated Vibe account contract publishes a compact catalog
        // readiness object. It remains fail-closed unless both its channel
        // flag and ready status are explicit.
        $type = strtolower(trim((string) ($plan['customerApiProductType'] ?? '')));
        if ($type === 'vibe' && array_key_exists('status', $raw)
            && !array_key_exists('salesEligible', $raw)) {
            return self::vibe($plan, $raw);
        }

        $channel = strtolower(trim((string) ($raw['channel'] ?? 'whmcs')));
        $channelSupported = self::bool($raw['channelSupported'] ?? null);
        $automatic = self::bool($raw['automaticProvisioningReady'] ?? null);
        $requestedEligible = self::bool($raw['salesEligible'] ?? null);
        $readiness = strtolower(trim((string) ($raw['readiness'] ?? self::UNKNOWN)));
        $location = strtolower(trim((string) (
            $raw['locationRequirement'] ?? 'required'
        )));
        if (!in_array($location, ['required', 'optional', 'not_applicable'], true)) {
            $location = 'required';
        }
        $checkedAt = self::timestamp($raw['checkedAt'] ?? null);
        $validUntil = self::timestamp($raw['validUntil'] ?? null);
        $blockers = self::blockers($raw['blockers'] ?? []);

        // A venda requer suporte ao canal e automação confirmada.
        $eligible = $requestedEligible
            && $channel === 'whmcs'
            && $channelSupported
            && $automatic
            && $readiness === self::READY;

        return [
            'known' => true,
            'legacy' => false,
            'schemaVersion' => max(1, (int) ($raw['schemaVersion'] ?? 1)),
            'channel' => $channel,
            'channelSupported' => $channelSupported,
            'automaticProvisioningReady' => $automatic,
            'salesEligible' => $eligible,
            'locationRequirement' => $location,
            'readiness' => $readiness,
            'blockers' => $blockers,
            'checkedAt' => $checkedAt,
            'validUntil' => $validUntil,
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $raw */
    private static function vibe(array $plan, array $raw): array
    {
        $ready = strtolower(trim((string) ($raw['status'] ?? ''))) === self::READY;
        $supported = self::bool($plan['channelSupported'] ?? null);
        $now = time();
        return [
            'known' => true,
            'legacy' => false,
            'schemaVersion' => 1,
            'channel' => 'whmcs',
            'channelSupported' => $supported,
            'automaticProvisioningReady' => $ready,
            'salesEligible' => $supported && $ready,
            'locationRequirement' => 'not_applicable',
            'readiness' => $ready ? self::READY : self::UNKNOWN,
            'blockers' => $supported && $ready ? [] : [[
                'code' => $supported ? 'readiness_unknown' : 'channel_not_supported',
                'retryable' => true,
                'action' => $supported
                    ? 'A conta Vibe ainda não está pronta para venda pelo WHMCS.'
                    : 'Este plano Vibe não está habilitado para venda pelo WHMCS.',
            ]],
            'checkedAt' => gmdate('c', $now),
            // Compact Vibe availability has no producer TTL. Apply the same
            // short local freshness window used by legacy catalog snapshots.
            'validUntil' => gmdate('c', $now + 300),
        ];
    }

    /** @param array<string,mixed> $availability */
    public static function isFresh(array $availability, ?int $now = null): bool
    {
        $validUntil = self::timestamp($availability['validUntil'] ?? null);
        if ($validUntil === null) {
            return false;
        }
        $until = strtotime($validUntil);
        if ($until === false) {
            return false;
        }
        return $until >= ($now ?? time());
    }

    /** @param array<string,mixed> $availability */
    public static function explain(array $availability): string
    {
        if (!($availability['known'] ?? false)) {
            return 'A disponibilidade deste plano ainda não foi confirmada pela Customer API.';
        }
        if (!self::isFresh($availability)) {
            return 'A confirmação de disponibilidade deste plano expirou. Atualize o catálogo antes de vender.';
        }
        $blockers = self::blockers($availability['blockers'] ?? []);
        if ($blockers !== []) {
            return $blockers[0]['action'];
        }
        if (($availability['channel'] ?? '') !== 'whmcs'
            || !($availability['channelSupported'] ?? false)) {
            return 'Este plano não está habilitado para venda pelo WHMCS.';
        }
        if (!($availability['automaticProvisioningReady'] ?? false)) {
            return 'A automação deste plano ainda não está pronta para venda pelo WHMCS.';
        }
        if (($availability['readiness'] ?? self::UNKNOWN) !== self::READY) {
            return 'Este plano ainda não está pronto para provisionamento automático.';
        }
        return 'Este plano não está disponível para venda no momento.';
    }

    /** @return list<array{code:string,retryable:bool,action:string}> */
    private static function blockers(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $blocker) {
            if (!is_array($blocker)) {
                continue;
            }
            $code = strtolower(trim((string) ($blocker['code'] ?? 'availability_unknown')));
            if ($code === '') {
                $code = 'availability_unknown';
            }
            $action = trim((string) ($blocker['action'] ?? 'Revise a disponibilidade deste plano antes de continuar.'));
            $out[] = [
                'code' => $code,
                'retryable' => self::bool($blocker['retryable'] ?? false),
                'action' => $action,
            ];
        }
        return $out;
    }

    private static function legacy(array $plan): array
    {
        $type = strtolower(trim((string) (
            $plan['customerApiProductType'] ?? ''
        )));
        $supported = self::bool(
            $plan['customerApiProvisioningSupported'] ?? false
        );
        $activation = strtolower(trim((string) (
            $plan['activationMode'] ?? 'auto'
        )));
        $requiresContract = self::bool($plan['requiresContract'] ?? false);
        $supportsContractTerms = self::bool(
            $plan['supportsContractTerms'] ?? false
        );
        $contractTerms = is_array($plan['contractTerms'] ?? null)
            ? $plan['contractTerms']
            : [];

        // Dados sem disponibilidade explícita só são aceitos quando são claros.
        $legacyVerified = $supported
            && (
                ($type === 'stack-container' && $activation === 'auto')
                || ($type === 'edge-cloud' && $activation === 'auto')
                || ($type === 'dedicated' && $activation === 'manual')
            )
            && (!$requiresContract || ($supportsContractTerms && $contractTerms !== []));
        $now = time();
        $reason = match (true) {
            !$supported => [
                'code' => 'channel_not_supported',
                'retryable' => false,
                'action' => 'Este plano não está habilitado para provisionamento pela Customer API.',
            ],
            $type === 'edge-cloud' && $activation === 'manual' => [
                'code' => 'manual_delivery_only',
                'retryable' => false,
                'action' => 'A automação deste plano Edge ainda não está pronta para venda pelo WHMCS.',
            ],
            !in_array($type, ['stack-container', 'edge-cloud', 'dedicated'], true) => [
                'code' => 'product_type_unknown',
                'retryable' => false,
                'action' => 'O tipo deste plano ainda não é compatível com o módulo WHMCS.',
            ],
            $requiresContract && (!$supportsContractTerms || $contractTerms === []) => [
                'code' => 'contract_catalog_incomplete',
                'retryable' => true,
                'action' => 'O catálogo contratual deste plano está incompleto.',
            ],
            default => [
                'code' => 'plan_configuration_incomplete',
                'retryable' => true,
                'action' => 'A configuração deste plano ainda não permite venda segura pelo WHMCS.',
            ],
        };

        return [
            'known' => $legacyVerified,
            'legacy' => true,
            'schemaVersion' => 0,
            'channel' => 'whmcs',
            'channelSupported' => $supported,
            'automaticProvisioningReady' => $legacyVerified,
            'salesEligible' => $legacyVerified,
            'locationRequirement' => 'required',
            'readiness' => $legacyVerified ? 'legacy_verified' : self::UNKNOWN,
            'blockers' => $legacyVerified ? [] : [$reason],
            'checkedAt' => gmdate('c', $now),
            'validUntil' => gmdate('c', $now + 300),
        ];
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private static function timestamp(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || strtotime($value) === false) {
            return null;
        }
        return $value;
    }
}
