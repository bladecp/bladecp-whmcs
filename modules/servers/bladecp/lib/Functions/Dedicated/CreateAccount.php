<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Dedicated;

use WHMCS\Module\Server\Bladecp\Api\Client;
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

/** Creates a Dedicated service, which is fulfilled through a manual lifecycle. */
final class CreateAccount
{
    public static function run(array $params): string
    {
        $client = Client::fromParams($params);
        $opts = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];
        $planId = $opts['BladeCP Plan']
            ?? $params['configoption2']
            ?? null;
        if (!is_numeric($planId) || (int) $planId <= 0) {
            return 'BladeCP: selecione um plano válido antes de provisionar.';
        }
        $locationLabelToId = [];
        $locations = [];
        try {
            $locations = Loaders::locations($client, $planId);
            foreach ($locations as $locationId => $label) {
                $label = trim((string) $label);
                if ($label !== '') {
                    $locationLabelToId[$label] = (int) $locationId;
                }
            }
        } catch (\Throwable) {
        }
        $optionGroupsResult = is_numeric($planId)
            ? OptionCatalog::groupsForPlan($client, (string) $planId)
            : null;
        if ($optionGroupsResult === null && SelectionRules::hasAdditionalSelection($opts)) {
            return 'BladeCP: não foi possível confirmar o catálogo necessário para validar as opções selecionadas. Tente novamente antes de provisionar.';
        }
        $optionGroups = $optionGroupsResult ?? [];
        $options = OptionsMapper::toOptions(
            $opts,
            OptionsMapper::valueMapFromGroups($optionGroups)
        );

        $body = ParamsMapper::toStackCreatePayload(
            $params,
            [],
            [],
            $locationLabelToId,
            $options,
            true,
            true
        );
        $localContractError = ParamsMapper::validateContractForPlan($body, null);
        if ($localContractError !== null) {
            return $localContractError;
        }
        $locationError = ParamsMapper::validateLocationForPlan(
            $body['location_id'] ?? null,
            $locations
        );
        if ($locationError !== null) {
            return $locationError;
        }

        $preflight = ProvisionPreflight::check(
            $client,
            (int) $planId,
            (int) ($params['pid'] ?? 0)
        );
        if (!$preflight['allowed']) {
            return $preflight['message'];
        }
        $contractError = ParamsMapper::validateContractForPlan(
            $body,
            $preflight['plan']
        );
        if ($contractError !== null) {
            return $contractError;
        }
        $key = 'whmcs-' . ($params['serviceid'] ?? '0') . '-create';
        $response = $client->post('/provisions/simple', $body, [
            'Idempotency-Key' => $key,
        ]);
        $uuid = $response['uuid'] ?? null;
        if (!ParamsMapper::isCanonicalUuid($uuid)) {
            Logger::logCall('CreateAccount', $body, $response);
            return 'BladeCP: a API não retornou um UUID de provisão válido.';
        }
        $status = strtolower(trim((string) ($response['status'] ?? '')));
        if ($status === '') {
            Logger::logCall('DedicatedCreateAccount', $body, $response);
            return 'BladeCP: a API criou a solicitação, mas não retornou o estado de entrega manual. Nenhuma confirmação local foi gravada.';
        }

        ServiceProperties::save($params, [
            'BladeCP Provision UUID' => $uuid,
            'BladeCP Product Type' => 'dedicated',
            'BladeCP Provision Status' => $status,
            'Last Sync' => date('Y-m-d H:i:s'),
        ]);

        Logger::logCall('CreateAccount', $body, $response);
        return 'success';
    }
}
