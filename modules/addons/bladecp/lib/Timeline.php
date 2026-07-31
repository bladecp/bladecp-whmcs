<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Timeline read-only e sanitizada de um serviço BladeCP.
 *
 * O WHMCS não mantém um event store do módulo. Por isso esta classe combina
 * somente evidências existentes e correlacionáveis, como log do módulo com o
 * ID do serviço ou UUID de provisão. Ausência de evidência nunca é tratada
 * como ausência de operação.
 */
final class Timeline
{
    /**
     * @return array{service:?array<string,mixed>,items:list<array<string,string>>,available:bool}
     */
    public static function forService(int $serviceId, int $limit = 25): array
    {
        $serviceId = max(0, $serviceId);
        $limit = max(1, min(50, $limit));
        $service = self::service($serviceId);
        if ($service === null) {
            return ['service' => null, 'items' => [], 'available' => false];
        }

        $needles = ['serviceId' => (string) $serviceId, 'service_id' => (string) $serviceId];
        if ($service['provisionUuid'] !== '') {
            $needles['provisionUuid'] = $service['provisionUuid'];
        }
        $items = [];
        foreach (Operation::moduleLogEntries('', 50) as $entry) {
            $haystack = strtolower((string) ($entry['action'] ?? '') . ' ' . (string) ($entry['message'] ?? ''));
            $matches = false;
            foreach ($needles as $key => $value) {
                if ($value !== '' && str_contains($haystack, strtolower($value))) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                continue;
            }
            $items[] = [
                'at' => (string) ($entry['at'] ?? ''),
                'kind' => (string) ($entry['status'] ?? 'recorded'),
                'title' => (string) ($entry['action'] ?? 'Ação BladeCP'),
                'detail' => (string) ($entry['message'] ?? ''),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        return ['service' => $service, 'items' => $items, 'available' => true];
    }

    /** @return array<string,mixed>|null */
    private static function service(int $serviceId): ?array
    {
        if ($serviceId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('tblhosting') || !$schema->hasTable('tblproducts')) {
                return null;
            }
            $row = \WHMCS\Database\Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
                ->where('h.id', $serviceId)
                ->where('p.servertype', 'bladecp')
                ->select(['h.id as serviceId', 'h.domainstatus as status', 'h.domain as domain', 'p.name as productName'])
                ->first();
            if ($row === null) {
                return null;
            }
            $uuid = '';
            if ($schema->hasTable('tblcustomfields') && $schema->hasTable('tblcustomfieldsvalues')) {
                $custom = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues as v')
                    ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
                    ->where('v.relid', $serviceId)
                    ->where('f.fieldname', 'BladeCP Provision UUID')
                    ->select('v.value')
                    ->first();
                $uuid = $custom !== null ? trim((string) ($custom->value ?? '')) : '';
            }
            return [
                'serviceId' => (int) ($row->serviceId ?? 0),
                'status' => trim((string) ($row->status ?? 'Unknown')),
                'domain' => self::plain((string) ($row->domain ?? '')),
                'productName' => self::plain((string) ($row->productName ?? '')),
                'provisionUuid' => self::plain($uuid),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function plain(string $value): string
    {
        $value = trim(strip_tags(Operation::redact($value)));
        return function_exists('mb_substr')
            ? mb_substr($value, 0, 180)
            : substr($value, 0, 180);
    }
}
