<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Snapshot pontual do catálogo usado pelas decisões de venda e preflight.
 * Não persiste nem mascara uma falha de rede: ausência de resposta é um
 * estado desconhecido e, por consequência, não libera provisão.
 */
final class CatalogSnapshot
{
    /** @var array<int,array{ok:bool,fetchedAt:string,plans:list<array<string,mixed>>,error:?string}> */
    private static array $requestCache = [];

    /**
     * @return array{ok:bool,fetchedAt:string,plans:list<array<string,mixed>>,error:?string}
     */
    public static function fetch(Client $client, bool $forceFresh = false): array
    {
        $key = spl_object_id($client);
        if (!$forceFresh && isset(self::$requestCache[$key])) {
            return self::$requestCache[$key];
        }
        try {
            $rows = $client->get('/partner/plans');
            if (isset($rows['data']) && is_array($rows['data'])) {
                $rows = $rows['data'];
            }
            $plans = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row)) {
                    $plans[] = $row;
                }
            }
            $snapshot = [
                'ok' => true,
                'fetchedAt' => gmdate('c'),
                'plans' => $plans,
                'error' => null,
            ];
        } catch (\Throwable) {
            $snapshot = [
                'ok' => false,
                'fetchedAt' => gmdate('c'),
                'plans' => [],
                'error' => 'Não foi possível confirmar o catálogo BladeCP agora.',
            ];
        }
        self::$requestCache[$key] = $snapshot;
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot */
    public static function plan(array $snapshot, int|string $planId): ?array
    {
        $needle = trim((string) $planId);
        if ($needle === '') {
            return null;
        }
        foreach (($snapshot['plans'] ?? []) as $plan) {
            if (is_array($plan) && (string) ($plan['id'] ?? '') === $needle) {
                return $plan;
            }
        }
        return null;
    }
}
