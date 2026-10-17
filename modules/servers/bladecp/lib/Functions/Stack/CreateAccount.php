<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

use WHMCS\Module\Server\Bladecp\Api\Catalog;
use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\OptionCatalog;
use WHMCS\Module\Server\Bladecp\Config\Loaders;
use WHMCS\Module\Server\Bladecp\Helpers\ComboResolver;
use WHMCS\Module\Server\Bladecp\Helpers\ComponentAvailability;
use WHMCS\Module\Server\Bladecp\Helpers\ComponentRef;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\OptionsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ParamsMapper;
use WHMCS\Module\Server\Bladecp\Helpers\ProvisionPreflight;
use WHMCS\Module\Server\Bladecp\Helpers\SelectionRules;
use WHMCS\Module\Server\Bladecp\Helpers\ServiceProperties;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Creates a Stack Container service from its selected plan and options. */
final class CreateAccount
{
    public static function run(array $params): string
    {
        $migration = \WHMCS\Module\Server\Bladecp\Helpers\FastDeployProvisionGuard::check($params);
        if ($migration !== null) {
            return $migration;
        }
        $client = Client::fromParams($params);

        $opts = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];
        // Um combo ativo define a seleção inteira; a escolha manual
        // de componentes (dropdowns por categoria + ConfigOption
        // avulsos) é DESCARTADA. Precedência: combo escolhido pelo
        // cliente no formulário > combo atribuído ao produto.
        $comboName = trim((string) ($opts['Combo'] ?? ''));
        $activeCombo = $comboName !== '' && $comboName !== '— Nenhum —'
            ? ComboResolver::slugsForComboName($comboName)
            : [];
        if ($activeCombo === []) {
            $activeCombo = ComboResolver::slugsForProduct(
                (int) ($params['pid'] ?? 0)
            );
        }
        // Resolve component names, categories and default versions.
        $richResult = Catalog::catalog($client);
        if ($richResult === null && SelectionRules::hasAdditionalSelection($opts)) {
            return 'BladeCP: não foi possível confirmar o catálogo necessário para validar os componentes e opções selecionados. Tente novamente antes de provisionar.';
        }
        $rich = $richResult ?? [];
        $nameToSlug = [];
        $defaultVersionBySlug = [];
        $slugCategory = [];
        foreach ($rich as $r) {
            $n = trim((string) ($r['name'] ?? ''));
            $s = trim((string) ($r['slug'] ?? ''));
            if ($n !== '' && $s !== '') {
                $nameToSlug[$n] = $s;
            }
            if ($s !== '') {
                $slugCategory[$s] = trim((string) ($r['category'] ?? ''));
            }
            if ($s === '') {
                continue;
            }
            $vs = $r['versions'] ?? [];
            $def = null;
            foreach ($vs as $v) {
                if (!empty($v['is_default'])) {
                    $def = (int) $v['id'];
                    break;
                }
            }
            if ($def === null && $vs !== []) {
                $def = (int) $vs[0]['id'];
            }
            if ($def !== null && $def > 0) {
                $defaultVersionBySlug[$s] = $def;
            }
        }
        // Conjunto final: combo ativo substitui tudo; sem combo
        // = seleção manual (dropdowns por categoria do order form +
        // ConfigOption avulsos).
        if ($activeCombo !== []) {
            $selected = ParamsMapper::mergeComponentSlugs($activeCombo, []);
        } else {
            $selected = ParamsMapper::mergeComponentSlugs(
                ParamsMapper::orderFormSlugs($opts, $rich, []),
                ParamsMapper::componentsFromParams($params)
            );
        }
        // A category choice that no longer matches the catalog would be
        // dropped by orderFormSlugs and the service created without it.
        if ($activeCombo === [] && $rich !== []) {
            $categoryNames = ComponentAvailability::groupCategoryNames();
            if ($categoryNames !== null) {
                $dropped = ComponentAvailability::unavailableSelections($opts, $categoryNames, $rich);
                if ($dropped !== []) {
                    $parts = [];
                    foreach ($dropped as $category => $label) {
                        $parts[] = $label . ' (' . $category . ')';
                    }
                    return 'BladeCP: opção fora do catálogo atual: ' . implode(', ', $parts)
                        . '. Ajuste as opções configuráveis do serviço antes de provisionar.';
                }
            }
        }
        if ($selected !== [] && $rich !== []) {
            $unavailable = ComponentAvailability::unavailableTokens(
                $selected,
                ComponentAvailability::index($rich)
            );
            if ($unavailable !== []) {
                return 'BladeCP: componente(s) fora do catálogo atual: '
                    . implode(', ', $unavailable)
                    . '. Confira o combo escolhido ou atribuído ao produto e o ConfigOption "BladeCP Components".';
            }
        }
        if ($selected !== []) {
            $catalog = Catalog::slugsFor($client);
            if ($catalog !== null) {
                // valida SLUGS (descarta a versão do token).
                $slugsOnly = array_map(
                    static fn (string $t) => ComponentRef::slug($t),
                    $selected
                );
                $unknown = Catalog::unknown($slugsOnly, $catalog);
                if ($unknown !== []) {
                    return 'BladeCP: componente(s) desconhecido(s) no catálogo: '
                        . implode(', ', $unknown)
                        . '. Confira o ConfigOption "BladeCP Components" '
                        . 'e o combo atribuído ao produto.';
                }
            }
            // Validate the final component selection when catalog data is available.
            if ($slugCategory !== []) {
                $ruleItems = array_map(
                    static fn (string $t) => [
                        'slug' => ComponentRef::slug($t),
                        'category' => $slugCategory[ComponentRef::slug($t)]
                            ?? '',
                    ],
                    $selected
                );
                $chk = SelectionRules::check($ruleItems);
                if (!$chk['ok']) {
                    return 'BladeCP: seleção inválida — ' . implode(
                        ' ',
                        $chk['errors']
                    );
                }
            }
        }

        $planId = $opts['BladeCP Plan']
            ?? $params['configoption2']
            ?? null;

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
            return 'BladeCP: não foi possível confirmar o catálogo necessário para validar os componentes e opções selecionados. Tente novamente antes de provisionar.';
        }
        $optionGroups = $optionGroupsResult ?? [];
        $options = OptionsMapper::toOptions(
            $opts,
            OptionsMapper::valueMapFromGroups($optionGroups)
        );

        $body = ParamsMapper::toStackCreatePayload(
            $params,
            $selected,
            $defaultVersionBySlug,
            $locationLabelToId,
            $options,
            false,
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
        if (!is_numeric($planId) || (int) $planId <= 0) {
            return 'BladeCP: selecione um plano válido antes de provisionar.';
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
            'BladeCP Product Type' => 'stack-container',
            'Last Sync' => date('Y-m-d H:i:s'),
        ]);

        Logger::logCall('CreateAccount', $body, $response);
        return 'success';
    }
}
