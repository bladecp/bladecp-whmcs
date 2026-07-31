<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Edge;

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

/** Creates an Edge Cloud service from its plan, location and selected options. */
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

        // Accept locations selected by label or identifier.
        $locationLabelToId = [];
        $locations = [];
        try {
            $locations = Loaders::locations($client, $planId);
            foreach ($locations as $lid => $llabel) {
                $llabel = trim((string) $llabel);
                if ($llabel !== '') {
                    $locationLabelToId[$llabel] = (int) $lid;
                }
            }
        } catch (\Throwable) {
        }

        // Map selected configurable options for the selected plan.
        $optionGroupsResult = is_numeric($planId)
            ? OptionCatalog::groupsForPlan($client, (string) $planId)
            : OptionCatalog::groupsFor($client);
        if ($optionGroupsResult === null && SelectionRules::hasAdditionalSelection($opts)) {
            return 'BladeCP: não foi possível confirmar o catálogo necessário para validar as opções selecionadas. Tente novamente antes de provisionar.';
        }
        $optionGroups = $optionGroupsResult ?? [];
        $options = OptionsMapper::toOptions(
            $opts,
            OptionsMapper::valueMapFromGroups($optionGroups)
        );

        // Edge services do not include Stack components.
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

        ServiceProperties::save($params, [
            'BladeCP Provision UUID' => $uuid,
            'BladeCP Product Type' => 'edge-cloud',
            'Last Sync' => date('Y-m-d H:i:s'),
        ]);

        Logger::logCall('CreateAccount', $body, $response);
        return 'success';
    }
}
