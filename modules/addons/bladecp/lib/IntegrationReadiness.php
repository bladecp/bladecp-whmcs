<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Leitura consolidada para a visão geral e o roteiro de primeiro uso. */
final class IntegrationReadiness
{
    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        $scope = self::scope();
        if ($scope === null) {
            return self::unknownSnapshot();
        }
        $catalog = self::catalogSummary($scope);
        $configured = ServerConfig::params() !== null;
        $products = self::productCount($scope);
        $ready = $configured && $products > 0 && $catalog['blocked'] === 0;
        return [
            'serverConfigured' => $configured,
            'catalogStateInstalled' => CatalogStateRepository::isInstalled(),
            'scope' => $scope,
            'products' => $products,
            'catalog' => $catalog,
            'ready' => $ready,
            'steps' => [
                ['key' => 'server', 'ready' => $configured, 'label' => 'Servidor BladeCP conectado'],
                ['key' => 'catalog', 'ready' => $products > 0, 'label' => 'Catálogo importado'],
                ['key' => 'availability', 'ready' => $catalog['total'] > 0 && $catalog['blocked'] === 0, 'label' => 'Disponibilidade confirmada'],
            ],
        ];
    }

    /**
     * Resolve um único grupo exclusivo do servidor administrativo. A leitura
     * não cria nem reaproveita grupos, pois indicadores não podem mudar o
     * estado da instalação. Dois grupos exclusivos ou grupo compartilhado
     * são ambíguos e retornam `null`.
     *
     * @return array{serverId:int,serverGroupId:int}|null
     */
    public static function scope(): ?array
    {
        if (!class_exists(AdminServerContext::class)
            || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $context = AdminServerContext::resolve();
            $serverId = (int) ($context['serverId'] ?? 0);
            if (($context['state'] ?? '') !== 'configured' || $serverId <= 0) {
                return null;
            }
            $db = \WHMCS\Database\Capsule::class;
            $schema = $db::schema();
            if (!$schema->hasTable('tblservergroupsrel')) {
                return null;
            }
            $groups = [];
            foreach ($db::table('tblservergroupsrel')
                ->where('serverid', $serverId)->get(['groupid']) as $row) {
                $groupId = (int) ($row->groupid ?? 0);
                if ($groupId <= 0) {
                    continue;
                }
                $members = [];
                foreach ($db::table('tblservergroupsrel')
                    ->where('groupid', $groupId)->get(['serverid']) as $member) {
                    $id = (int) ($member->serverid ?? 0);
                    if ($id > 0) {
                        $members[$id] = true;
                    }
                }
                if (array_keys($members) === [$serverId]) {
                    $groups[$groupId] = true;
                }
            }
            if (count($groups) !== 1) {
                return null;
            }
            return [
                'serverId' => $serverId,
                'serverGroupId' => (int) array_key_first($groups),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{serverId:int,serverGroupId:int} $scope */
    public static function productCount(array $scope): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            return (int) \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('servergroup', $scope['serverGroupId'])
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array{serverId:int,serverGroupId:int} $scope
     * @return array{total:int,eligible:int,blocked:int,unknown:int,stale:int,lastObservedAt:?string}
     */
    public static function catalogSummary(array $scope): array
    {
        $out = ['total' => 0, 'eligible' => 0, 'blocked' => 0, 'unknown' => 0, 'stale' => 0, 'lastObservedAt' => null];
        foreach (CatalogStateRepository::all() as $state) {
            if ((int) ($state['serverId'] ?? 0) !== $scope['serverId']
                || (int) ($state['serverGroupId'] ?? 0) !== $scope['serverGroupId']) {
                continue;
            }
            $out['total']++;
            if (!empty($state['effectiveSalesEligible'])) {
                $out['eligible']++;
            } else {
                $out['blocked']++;
            }
            if (($state['observedReadiness'] ?? '') === 'unknown') {
                $out['unknown']++;
            }
            $until = trim((string) ($state['validUntil'] ?? ''));
            if ($until !== '' && ($at = strtotime($until)) !== false && $at <= time()) {
                $out['stale']++;
            }
            $observed = trim((string) ($state['observedAt'] ?? ''));
            if ($observed !== '' && ($out['lastObservedAt'] === null || $observed > $out['lastObservedAt'])) {
                $out['lastObservedAt'] = $observed;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function unknownSnapshot(): array
    {
        $catalog = ['total' => 0, 'eligible' => 0, 'blocked' => 0, 'unknown' => 0, 'stale' => 0, 'lastObservedAt' => null];
        return [
            'serverConfigured' => false,
            'catalogStateInstalled' => CatalogStateRepository::isInstalled(),
            'scope' => null,
            'products' => 0,
            'catalog' => $catalog,
            'ready' => false,
            'unknown' => true,
            'steps' => [
                ['key' => 'server', 'ready' => false, 'label' => 'Servidor BladeCP não selecionado'],
                ['key' => 'catalog', 'ready' => false, 'label' => 'Catálogo sem contexto de servidor'],
                ['key' => 'availability', 'ready' => false, 'label' => 'Disponibilidade não confirmada'],
            ],
        ];
    }
}
