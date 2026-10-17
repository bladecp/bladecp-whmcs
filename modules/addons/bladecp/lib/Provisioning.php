<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * O assistente oferece ao instalador, em um clique, um produto de
 * teste pronto pra provisionar — Stack Container, em um grupo OCULTO,
 * gratuito (R$0), já ligado ao módulo e a um server group com o
 * servidor BladeCP. Ele só finaliza escolhendo o Plan no editor do
 * WHMCS (id dinâmico via API, que não dá pra adivinhar aqui).
 *
 * Tudo find-or-create (idempotente — reexecutar não duplica),
 * filtrado pelas colunas que existem (tolera variação de schema) e
 * fail-soft (sem Capsule → no-op/erro estruturado, nunca fatal).
 *
 * Mapa dos ConfigOptions do módulo (ordem fixa que o server module
 * consome): configoption1 = BladeCP Product Type, configoption2 =
 * Plan, configoption3 = Components. Só o #1 é pré-setado
 * (stack-container); Plan fica pro admin.
 */
final class Provisioning
{
    public const PRODUCT_GROUP_NAME = 'BladeCP (testes)';
    public const SERVER_GROUP_NAME = 'BladeCP';
    public const PRODUCT_NAME = 'BladeCP Stack (teste)';

    /** Produto de teste já existente → id, ou null. Fail-soft. */
    public static function findStarterProduct(?int $serverId = null): ?int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $serverId = self::resolvedServerId($serverId);
            $serverGroupId = $serverId === null ? 0 : self::serverGroup($serverId);
            if ($serverId === null || $serverGroupId <= 0) {
                return null;
            }
            $r = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('name', self::starterProductName($serverId))
                ->where('servergroup', $serverGroupId)
                ->first();

            return $r !== null ? (int) $r->id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cria (ou reaproveita) o produto de teste. Cada sub-passo é
     * independente: se o server group falhar, o produto ainda é criado
     * (servergroup=0) e o admin ajusta no editor.
     *
     * @return array{ok:bool,pid:int,mode:string,error:?string}
     */
    public static function createStarterProduct(): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'pid' => 0, 'mode' => '', 'error' => 'Capsule indisponível.'];
        }
        try {
            $serverId = self::resolvedServerId();
            $serverGroupId = $serverId === null ? 0 : self::serverGroup($serverId);
            if ($serverId === null || $serverGroupId <= 0) {
                return ['ok' => false, 'pid' => 0, 'mode' => '', 'error' => I18n::t('admin.setup.starter_server_required')];
            }
            $existing = self::findStarterProduct($serverId);
            if ($existing !== null) {
                return ['ok' => true, 'pid' => $existing, 'mode' => 'exists', 'error' => null];
            }

            $cols = array_flip(
                \WHMCS\Database\Capsule::schema()->getColumnListing('tblproducts')
            );
            $pid = (int) \WHMCS\Database\Capsule::table('tblproducts')
                ->insertGetId(array_intersect_key([
                    'type' => 'hostingaccount',
                    'gid' => self::hiddenProductGroup($serverId),
                    'name' => self::starterProductName($serverId),
                    'description' => 'Produto de teste BladeCP (Stack Container) '
                        . 'em grupo oculto. Gratuito — provisiona sem cobrança.',
                    'hidden' => 0,
                    'showdomainoptions' => 0,
                    'paytype' => 'free',
                    'servertype' => 'bladecp',
                    'servergroup' => $serverGroupId,
                    'configoption1' => 'stack-container',
                    'autosetup' => 'order',
                ], $cols));

            CatalogSlugs::healProduct($pid, self::starterProductName($serverId));

            return ['ok' => true, 'pid' => $pid, 'mode' => 'created', 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'pid' => 0, 'mode' => '', 'error' => $e->getMessage()];
        }
    }

    /** Grupo de produtos OCULTO (find-or-create) → id, ou 0 fail-soft. */
    public static function hiddenProductGroup(?int $serverId = null): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            $serverId = self::resolvedServerId($serverId);
            if ($serverId === null) {
                return 0;
            }
            return CatalogSlugs::ensureGroup(self::starterGroupName($serverId), 1);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Server group contendo o servidor BladeCP (find-or-create + liga o
     * servidor) → id, ou 0 fail-soft (ex.: nenhum servidor ainda).
     */
    public static function serverGroup(?int $serverId = null): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            $serverId = self::resolvedServerId($serverId);
            if ($serverId === null || !self::isBladecpServer($serverId)) {
                return 0;
            }
            // Reaproveita somente um grupo que contenha este servidor e
            // nenhum outro. Relações legadas compartilhadas são ambíguas e
            // nunca são reatribuídas silenciosamente.
            $rels = \WHMCS\Database\Capsule::table('tblservergroupsrel')
                ->where('serverid', $serverId)
                ->orderBy('groupid')
                ->get(['groupid']);
            foreach ($rels as $rel) {
                $gid = (int) ($rel->groupid ?? 0);
                if ($gid > 0 && self::groupIsExclusiveForServer($gid, $serverId)) {
                    return $gid;
                }
            }

            $name = self::serverGroupName($serverId);
            $groups = \WHMCS\Database\Capsule::table('tblservergroups')
                ->where('name', $name)
                ->orderBy('id')
                ->get(['id']);
            foreach ($groups as $group) {
                $gid = (int) ($group->id ?? 0);
                if ($gid > 0 && self::groupIsExclusiveForServer($gid, $serverId)) {
                    return $gid;
                }
            }
            $cols = array_flip(
                \WHMCS\Database\Capsule::schema()->getColumnListing('tblservergroups')
            );
            $gid = (int) \WHMCS\Database\Capsule::table('tblservergroups')
                ->insertGetId(array_intersect_key([
                    'name' => $name,
                    'filltype' => 1,
                ], $cols));
            if ($gid <= 0) {
                return 0;
            }
            \WHMCS\Database\Capsule::table('tblservergroupsrel')->insert([
                'groupid' => $gid,
                'serverid' => $serverId,
            ]);

            return $gid;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Nome estável de um grupo exclusivo do servidor. Puro. */
    public static function serverGroupName(int $serverId): string
    {
        return self::SERVER_GROUP_NAME . ' #' . max(0, $serverId);
    }

    /** Nome do produto de teste, isolado por servidor quando necessário. */
    public static function starterProductName(int $serverId): string
    {
        return self::isSingleServerContext($serverId)
            ? self::PRODUCT_NAME
            : self::PRODUCT_NAME . ' #' . max(0, $serverId);
    }

    /** Nome do grupo oculto de teste, isolado por servidor quando necessário. */
    public static function starterGroupName(int $serverId): string
    {
        return self::isSingleServerContext($serverId)
            ? self::PRODUCT_GROUP_NAME
            : self::PRODUCT_GROUP_NAME . ' #' . max(0, $serverId);
    }

    private static function resolvedServerId(?int $serverId): ?int
    {
        if ($serverId !== null && $serverId > 0) {
            return $serverId;
        }
        if (!class_exists(AdminServerContext::class)) {
            return null;
        }
        $context = AdminServerContext::resolve();
        return ($context['state'] ?? '') === 'configured'
            && (int) ($context['serverId'] ?? 0) > 0
            ? (int) $context['serverId']
            : null;
    }

    private static function isBladecpServer(int $serverId): bool
    {
        try {
            return \WHMCS\Database\Capsule::table('tblservers')
                ->where('id', $serverId)
                ->where('type', 'bladecp')
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function groupIsExclusiveForServer(int $groupId, int $serverId): bool
    {
        try {
            $ids = [];
            foreach (\WHMCS\Database\Capsule::table('tblservergroupsrel')
                ->where('groupid', $groupId)->get(['serverid']) as $rel) {
                $id = (int) ($rel->serverid ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
            return array_keys($ids) === [$serverId];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function isSingleServerContext(int $serverId): bool
    {
        if (!class_exists(AdminServerContext::class)) {
            return false;
        }
        $context = AdminServerContext::resolve();
        return count($context['candidates'] ?? []) === 1
            && (int) ($context['serverId'] ?? 0) === $serverId;
    }
}
