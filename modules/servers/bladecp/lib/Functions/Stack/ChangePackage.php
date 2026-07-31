<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;
use WHMCS\Module\Server\Bladecp\Api\OptionCatalog;
use WHMCS\Module\Server\Bladecp\Config\Loaders;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\OptionsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ParamsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ProvisionPreflight;
use WHMCS\Module\Server\Bladecp\Helpers\SelectionRules;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Updates a service plan and reconciles its configurable options. */
final class ChangePackage
{
    public static function run(array $params): string
    {
        $uuid = ServiceProperties::provisionUuid($params);
        if ($uuid === null) {
            return 'BladeCP: provision UUID ausente.';
        }
        $newPlan = $params['configoptions']['BladeCP Plan']
            ?? $params['configoption2']
            ?? null;
        if (!is_numeric($newPlan)) {
            return 'BladeCP: BladeCP Plan (partner_plan_id) inválido no produto.';
        }
        $client = Client::fromParams($params);
        $destinationError = self::validateDestination(
            $client,
            $params,
            (int) $newPlan
        );
        if ($destinationError !== null) {
            return $destinationError;
        }
        $optionsError = self::validateOptionsCatalog($client, $params, (int) $newPlan);
        if ($optionsError !== null) {
            return $optionsError;
        }
        $key = 'whmcs-' . ($params['serviceid'] ?? '0') . '-changepackage-' . $newPlan;

        $response = $client->patch(
            "/provisions/{$uuid}",
            ['partnerPlanId' => (int) $newPlan],
            ['Idempotency-Key' => $key]
        );
        if (!self::isMeaningfulProvisionResponse($response, $uuid)) {
            Logger::logCall('ChangePackage', ['uuid' => $uuid, 'plan' => $newPlan], $response);
            return 'BladeCP: a API confirmou a troca de pacote sem retornar um estado utilizável da provisão. Nenhuma nova sincronização local foi gravada.';
        }

        $optError = self::syncOptions($client, $params, $uuid, (int) $newPlan);

        ServiceProperties::save($params, ['Last Sync' => date('Y-m-d H:i:s')]);
        Logger::logCall('ChangePackage', ['uuid' => $uuid, 'plan' => $newPlan], $response);

        // Report option reconciliation errors after the plan update.
        if ($optError !== null) {
            return $optError;
        }
        return 'success';
    }

    /** Validates the destination plan before applying it to the service. */
    private static function validateDestination(
        Client $client,
        array $params,
        int $newPlan
    ): ?string {
        $preflight = ProvisionPreflight::check(
            $client,
            $newPlan,
            (int) ($params['pid'] ?? 0)
        );
        if (!$preflight['allowed']) {
            return $preflight['message'];
        }
        $locations = Loaders::locations($client, $newPlan);
        $locationLabels = [];
        foreach ($locations as $id => $label) {
            $label = trim((string) $label);
            if ($label !== '') {
                $locationLabels[$label] = (int) $id;
            }
        }
        $payload = ParamsMapper::toStackCreatePayload(
            $params,
            [],
            [],
            $locationLabels,
            [],
            true,
            true
        );
        $locationError = ParamsMapper::validateLocationForPlan(
            $payload['location_id'] ?? null,
            $locations
        );
        if ($locationError !== null) {
            return $locationError;
        }
        return ParamsMapper::validateContractForPlan($payload, $preflight['plan']);
    }

    /** Ensures selected options can be validated before the plan is changed. */
    private static function validateOptionsCatalog(
        Client $client,
        array $params,
        int $newPlan
    ): ?string {
        $groups = OptionCatalog::groupsForPlan($client, $newPlan);
        $opts = is_array($params['configoptions'] ?? null)
            ? $params['configoptions'] : [];
        if ($groups === null && SelectionRules::hasAdditionalSelection($opts)) {
            return 'BladeCP: não foi possível confirmar o catálogo necessário para validar as opções selecionadas. Tente novamente antes de alterar o plano.';
        }
        return null;
    }

    /** @param array<string,mixed> $response */
    private static function isMeaningfulProvisionResponse(array $response, string $uuid): bool
    {
        $returnedUuid = trim((string) ($response['uuid'] ?? ''));
        if ($returnedUuid !== '' && hash_equals($uuid, $returnedUuid)) {
            return true;
        }
        return trim((string) ($response['status'] ?? '')) !== '';
    }

    /** Synchronizes configurable options for the newly selected plan. */
    private static function syncOptions(
        Client $client,
        array $params,
        string $uuid,
        int $newPlan
    ): ?string {
        // Option lookup is best-effort.
        try {
            $groupsResult = $newPlan > 0
                ? OptionCatalog::groupsForPlan($client, $newPlan)
                : OptionCatalog::groupsFor($client);
            $groups = $groupsResult ?? [];
            if ($groupsResult === null && SelectionRules::hasAdditionalSelection(
                is_array($params['configoptions'] ?? null)
                    ? $params['configoptions'] : []
            )) {
                return 'BladeCP: o plano foi alterado, mas não foi possível confirmar o catálogo das opções selecionadas. Tente novamente antes de continuar.';
            }
            $valueMap = OptionsMapper::valueMapFromGroups($groups);
        } catch (\Throwable $e) {
            Logger::logCall(
                'ChangePackageOptions',
                ['uuid' => $uuid],
                ['resolve_error' => $e->getMessage()]
            );
            return null;
        }
        if ($valueMap === []) {
            return null;
        }
        $opts = is_array($params['configoptions'] ?? null)
            ? $params['configoptions'] : [];
        $resolved = OptionsMapper::toOptions($opts, $valueMap);
        $body = self::optionsBody($resolved);
        $key = 'whmcs-' . ($params['serviceid'] ?? '0')
            . '-options-' . md5(json_encode($body, JSON_THROW_ON_ERROR));

        // Surface errors from the options update.
        try {
            $resp = $client->patch(
                "/provisions/{$uuid}/options",
                ['options' => $body],
                ['Idempotency-Key' => $key]
            );
            Logger::logCall(
                'ChangePackageOptions',
                ['uuid' => $uuid, 'options' => $body],
                $resp
            );
            return null;
        } catch (\Throwable $e) {
            Logger::logCall(
                'ChangePackageOptions',
                ['uuid' => $uuid, 'options' => $body],
                ['error' => $e->getMessage()]
            );
            $status = $e instanceof ApiException ? $e->httpStatus : 0;
            return self::optionsErrorMessage($status, $e->getMessage());
        }
    }

    /**
     * @param array<int,array{option_id:string,option_value_id:string,quantity:int}> $resolved
     * @return array<int,array{optionUuid:string,optionValueUuid:string,quantity:int}>
     */
    public static function optionsBody(array $resolved): array
    {
        return array_map(static fn (array $o): array => [
            'optionUuid' => $o['option_id'],
            'optionValueUuid' => $o['option_value_id'],
            'quantity' => $o['quantity'],
        ], $resolved);
    }

    /** Builds a user-facing error for an options update failure. */
    public static function optionsErrorMessage(int $httpStatus, string $apiMessage): string
    {
        $apiMessage = trim($apiMessage);
        $base = 'BladeCP: o plano foi alterado, mas a reconciliação das '
            . 'opções configuráveis falhou';
        if ($httpStatus === 409) {
            return $base . ' (conflito 409'
                . ($apiMessage !== '' ? ': ' . $apiMessage : '')
                . '). Ajuste as opções e tente novamente (idempotente).';
        }
        return $base
            . ($apiMessage !== '' ? ': ' . $apiMessage : '')
            . '. Tente novamente (idempotente).';
    }
}
