<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Diagnóstico local, sem rede e sem mudança de estado. */
final class CatalogIntegrity
{
    /** @return array<string,mixed> */
    public static function inspect(): array
    {
        $scope = class_exists(IntegrationReadiness::class)
            ? IntegrationReadiness::scope() : null;
        if ($scope === null) {
            return [
                'ok' => false,
                'unknown' => true,
                'scope' => null,
                'summary' => ['total' => 0, 'eligible' => 0, 'blocked' => 0, 'unknown' => 0, 'stale' => 0, 'lastObservedAt' => null],
                'issues' => [self::issue('catalog_scope_unknown', 'Não foi possível associar o catálogo a um único servidor e grupo BladeCP.', 'select_server')],
            ];
        }
        $summary = IntegrationReadiness::catalogSummary($scope);
        $issues = [];
        if (!CatalogStateRepository::isInstalled()) {
            $issues[] = self::issue('catalog_state_missing', 'O controle de disponibilidade ainda não foi instalado.', 'activate_addon');
        }
        if ($summary['total'] === 0) {
            $issues[] = self::issue('availability_not_observed', 'Nenhum produto possui uma observação de disponibilidade.', 'refresh_catalog');
        }
        if ($summary['stale'] > 0) {
            $issues[] = self::issue('availability_stale', $summary['stale'] . ' produto(s) têm disponibilidade expirada.', 'refresh_catalog');
        }
        if ($summary['unknown'] > 0) {
            $issues[] = self::issue('availability_unknown', $summary['unknown'] . ' produto(s) não têm disponibilidade verificável.', 'refresh_catalog');
        }
        if ($summary['blocked'] > 0) {
            $issues[] = self::issue('sales_blocked', $summary['blocked'] . ' produto(s) estão ocultos para novas vendas.', 'review_catalog');
        }
        return [
            'ok' => $issues === [],
            'unknown' => false,
            'scope' => $scope,
            'summary' => $summary,
            'issues' => $issues,
        ];
    }

    /** @return array{code:string,message:string,action:string} */
    private static function issue(string $code, string $message, string $action): array
    {
        return ['code' => $code, 'message' => $message, 'action' => $action];
    }
}
