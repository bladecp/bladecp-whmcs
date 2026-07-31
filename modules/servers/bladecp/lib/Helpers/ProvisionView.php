<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\Client;
use WHMCS\Module\Server\Bladecp\Api\Exceptions\ApiException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Fonte ÚNICA de resolução + normalização da provisão VIVA p/ as
 * superfícies de UI: client area (`ClientPanel`) e admin
 * (`AdminPanel`). Evita duplicar fetch/map nas duas. Tudo fail-soft:
 * sem creds/uuid/rede → array com `status:'unknown'` e campos vazios
 * (a UI nunca quebra).
 *
 * Resolução do uuid robusta a contexto: `ServiceProperties::
 * provisionUuid` (lê serviceProperties + customfields — o uuid
 * persiste como custom field do produto) e, se ainda vazio, reverse
 * lookup determinístico por `external_service_id` (= service id do
 * WHMCS, mandado no create).
 */
final class ProvisionView
{
    /**
     * @return array{
     *   provisionUuid:string,status:string,domain:string,plan:string,
     *   productName:string,location:string,
     *   components:array<int,array{name:string,version:string,status:string}>,
     *   created:string,resources:array<string,string>,usage:array<string,string>,
     *   usageMetrics:array<int,array{label:string,used:string,limit:string,
     *     unit:string,percent:float|null}>,combo:string,comboSource:string,
     *   usageStatus:string,usageAsOf:string,placement:string,penalty:string,
     *   resolved:bool
     * }
     */
    public static function resolve(array $params): array
    {
        $uuid = ServiceProperties::provisionUuid($params);
        $detail = [];
        $client = null;
        try {
            $client = Client::fromParams($params);
            if ($uuid !== null && $uuid !== '') {
                $detail = $client->get("/provisions/{$uuid}");
            }
            if (
                !is_array($detail)
                || trim((string) ($detail['uuid'] ?? '')) === ''
            ) {
                $sid = (int) ($params['serviceid'] ?? 0);
                if ($sid > 0) {
                    $row = self::findByExternalServiceId(
                        $client,
                        (string) $sid
                    );
                    if ($row !== null) {
                        $detail = $row;
                    }
                }
            }
        } catch (\Throwable) {
            $detail = [];
        }
        if (!is_array($detail)) {
            $detail = [];
        }

        $d = is_array($detail['data'] ?? null) ? $detail['data'] : [];
        $plan = is_array($d['plan'] ?? null) ? $d['plan'] : [];
        $loc = is_array($d['location'] ?? null) ? $d['location'] : [];

        $locLabel = trim((string) ($loc['region'] ?? ''));
        $city = trim((string) ($loc['city'] ?? ''));
        $cc = trim((string) ($loc['countryCode'] ?? ''));
        if ($city !== '') {
            $locLabel = $locLabel !== ''
                ? $locLabel . ' · ' . $city : $city;
        }
        if ($cc !== '') {
            $locLabel .= ' (' . $cc . ')';
        }

        $components = [];
        foreach (
            is_array($d['components'] ?? null) ? $d['components'] : [] as $c
        ) {
            if (!is_array($c)) {
                continue;
            }
            $components[] = [
                'name' => (string) ($c['name'] ?? ''),
                'version' => (string) (
                    $c['version']['label'] ?? $c['version'] ?? ''
                ),
                'status' => strtolower((string) ($c['status'] ?? '')),
            ];
        }

        $num = static fn ($v): string => is_numeric($v)
            ? (string) (0 + $v) : '';
        $resources = [];
        if ($num($plan['cpu'] ?? null) !== '') {
            $resources['vCPU'] = $num($plan['cpu']);
        }
        if ($num($plan['ramGb'] ?? null) !== '') {
            $resources['RAM'] = $num($plan['ramGb']) . ' GB';
        }
        if ($num($plan['diskGb'] ?? null) !== '') {
            $resources['Disco'] = $num($plan['diskGb']) . ' GB';
        }

        $usageView = self::usageView($client, (string) ($detail['uuid'] ?? $uuid ?? ''));
        $comboView = self::comboView($params);
        $placement = self::placementLabel($detail['logicalPlacement'] ?? null);
        $penalty = self::penaltyLabel($detail['earlyTerminationPenalty'] ?? null);

        // ISO 8601 → pt-BR (19/05/2026 04:38). Fail-soft → cru.
        $created = self::formatDate(
            (string) ($detail['createdAt'] ?? $detail['created_at'] ?? '')
        );

        $resolvedUuid = (string) (
            $detail['uuid'] ?? $uuid ?? ''
        );
        $storedStatus = trim((string) ServiceProperties::get(
            $params,
            'BladeCP Provision Status',
            ''
        ));
        $liveStatus = trim((string) ($detail['status'] ?? ''));

        return [
            'provisionUuid' => $resolvedUuid,
            'status' => $liveStatus !== '' ? $liveStatus
                : ($storedStatus !== '' ? $storedStatus : 'unknown'),
            'domain' => (string) (
                $detail['primaryDomain']
                ?? $detail['primary_domain']
                ?? $d['primary_domain']
                ?? ($params['domain'] ?? '')
            ),
            'plan' => (string) ($plan['name'] ?? ''),
            'productName' => (string) ($d['product']['name'] ?? ''),
            'location' => $locLabel,
            'components' => $components,
            'created' => $created,
            'resources' => $resources,
            'usage' => $usageView['values'],
            'usageMetrics' => $usageView['metrics'],
            'usageStatus' => $usageView['status'],
            'usageAsOf' => $usageView['asOf'],
            'combo' => $comboView['name'],
            'comboSource' => $comboView['source'],
            'placement' => $placement,
            'penalty' => $penalty,
            // true = veio uma provisão de verdade da API (≠ fail-soft).
            'resolved' => trim((string) ($detail['uuid'] ?? '')) !== '',
        ];
    }

    /**
     * 1ª provisão de uma resposta de lista (`Client::get` já
     * desembrulha o envelope `data`): lista → 1º item; objeto único
     * com `uuid` → ele mesmo; senão null. Puro.
     *
     * @return array<string,mixed>|null
     */
    private static function firstProvision(mixed $list): ?array
    {
        if (!is_array($list) || $list === []) {
            return null;
        }
        if (array_is_list($list)) {
            $first = $list[0] ?? null;
            return is_array($first) ? $first : null;
        }
        return isset($list['uuid']) ? $list : null;
    }

    /** @return array<string,mixed>|null */
    private static function byExternalServiceId(mixed $list, string $serviceId): ?array
    {
        if (is_array($list)) {
            $external = (string) ($list['externalServiceId']
                ?? $list['external_service_id'] ?? '');
            if ($external !== '' && hash_equals($serviceId, $external)) {
                return $list;
            }
        }
        $items = is_array($list) && isset($list['items']) && is_array($list['items'])
            ? $list['items'] : $list;
        if (!is_array($items)) {
            return null;
        }
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $external = (string) ($item['externalServiceId']
                ?? $item['external_service_id'] ?? '');
            if ($external !== '' && hash_equals($serviceId, $external)) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Busca eficiente pelo filtro que a Customer API atual oferece. A API
     * anterior ignorava ou rejeitava esse parâmetro, portanto 400/404 ou
     * uma lista vazia caem no filtro local legado. A resposta filtrada ainda
     * é conferida por `externalServiceId`: isso evita aceitar o primeiro
     * item caso uma instalação antiga ignore silenciosamente a query.
     *
     * @return array<string,mixed>|null
     */
    private static function findByExternalServiceId(
        Client $client,
        string $serviceId
    ): ?array {
        try {
            $filtered = $client->get(
                '/provisions?external_service_id=' . rawurlencode($serviceId)
            );
            $found = self::byExternalServiceId($filtered, $serviceId);
            if ($found !== null) {
                return $found;
            }
            // A projeção pública não precisa repetir o identificador
            // interno do WHMCS. Quando o filtro canônico devolve uma única
            // provisão, ela é o resultado seguro da busca escopada.
            $items = is_array($filtered) && isset($filtered['items'])
                && is_array($filtered['items'])
                ? $filtered['items'] : $filtered;
            if (is_array($items) && array_is_list($items) && count($items) === 1) {
                return is_array($items[0]) ? $items[0] : null;
            }
        } catch (ApiException $e) {
            if (!in_array($e->httpStatus, [400, 404], true)) {
                throw $e;
            }
        }

        return self::byExternalServiceId(
            $client->get('/provisions'),
            $serviceId
        );
    }

    /**
     * @return array{
     *   values:array<string,string>,
     *   metrics:array<int,array{label:string,used:string,limit:string,
     *     unit:string,percent:float|null}>,
     *   status:string,
     *   asOf:string
     * }
     */
    private static function usageView(?Client $client, string $uuid): array
    {
        $empty = ['values' => [], 'metrics' => [], 'status' => '', 'asOf' => ''];
        if ($client === null || trim($uuid) === '') {
            return $empty;
        }
        try {
            $data = $client->get('/provisions/' . rawurlencode($uuid) . '/usage');
            $hasUsage = is_array($data['usage'] ?? null);
            $hasPlan = is_array($data['plan'] ?? null);
            if (!$hasUsage && !$hasPlan) {
                return $empty;
            }
            $usage = $hasUsage ? $data['usage'] : [];
            $plan = $hasPlan ? $data['plan'] : [];
            $values = [];
            $metrics = [];
            foreach ([
                'diskGb' => 'Disco',
                'bandwidthGbMonth' => 'Banda mensal',
            ] as $key => $label) {
                $used = $usage[$key] ?? null;
                $limit = $plan[$key] ?? null;
                if (!is_numeric($used) && !is_numeric($limit)) {
                    continue;
                }
                $unit = ' GB';
                $usedText = is_numeric($used) ? (string) (0 + $used) . $unit : '—';
                $limitText = is_numeric($limit) ? (string) (0 + $limit) . $unit : '—';
                $values[$label] = $usedText . ' de ' . $limitText;
                $percent = is_numeric($used) && is_numeric($limit)
                    && (float) $limit > 0
                    ? round(((float) $used / (float) $limit) * 100, 1)
                    : null;
                $metrics[] = [
                    'label' => $label,
                    'used' => is_numeric($used) ? (string) (0 + $used) : '',
                    'limit' => is_numeric($limit) ? (string) (0 + $limit) : '',
                    'unit' => trim($unit),
                    'percent' => $percent,
                ];
            }
            return [
                'values' => $values,
                'metrics' => $metrics,
                'status' => (string) ($data['status'] ?? ''),
                'asOf' => self::formatDate((string) ($data['asOf'] ?? '')),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** @return array{name:string,source:string} */
    private static function comboView(array $params): array
    {
        $options = is_array($params['configoptions'] ?? null)
            ? $params['configoptions'] : [];
        $selected = trim((string) ($options['Combo'] ?? $options['combo'] ?? ''));
        $normalized = strtolower(trim($selected, " \t\n\r\0\x0B—-"));
        if (
            $selected !== ''
            && !in_array($normalized, ['nenhum', 'none', 'ninguno'], true)
        ) {
            return ['name' => $selected, 'source' => 'customer'];
        }

        $assigned = ComboResolver::nameForProduct((int) ($params['packageid'] ?? 0));
        return $assigned !== ''
            ? ['name' => $assigned, 'source' => 'product']
            : ['name' => '', 'source' => ''];
    }

    private static function placementLabel(mixed $placement): string
    {
        if (!is_array($placement)) {
            return '';
        }
        $state = strtolower(trim((string) ($placement['assignmentState'] ?? '')));
        $name = trim((string) ($placement['assignedServerName'] ?? ''));
        // A API já projeta um nome próprio para o cliente. Nunca use IDs,
        // pool labels ou endereços mesmo que apareçam numa resposta futura.
        if ($name !== '') {
            return $name;
        }
        return match ($state) {
            'pending', 'unassigned' => 'Aguardando definição',
            'assigned' => 'Definida',
            default => '',
        };
    }

    private static function penaltyLabel(mixed $penalty): string
    {
        if (!is_array($penalty) || !is_numeric($penalty['amount'] ?? null)) {
            return '';
        }
        $currency = strtoupper(trim((string) ($penalty['currency'] ?? '')));
        $amount = (int) $penalty['amount'];
        if ($currency === 'BRL') {
            return 'R$ ' . number_format($amount / 100, 2, ',', '.');
        }
        return ($currency !== '' ? $currency . ' ' : '') . (string) $amount;
    }

    /**
     * ISO 8601 → pt-BR (`19/05/2026 04:38`). Pura (sem rede),
     * fail-soft: string vazia → `''`; data inválida → a string crua.
     * Fonte ÚNICA de formatação de data das superfícies do módulo
     * (reusada pela view de pedidos do marketplace).
     */
    public static function formatDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        try {
            return (new \DateTime($raw))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
