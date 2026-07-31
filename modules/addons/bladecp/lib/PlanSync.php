<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\PlanCatalog;
use WHMCS\Module\Server\Bladecp\Config\Loaders;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\SaleGuard;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Importador de planos: traz os planos da plataforma BladeCP e cria, em um clique, as
 * CATEGORIAS (grupos de produtos) + os PRODUTOS no WHMCS, já ligados ao
 * módulo. Nome = nome do plano no catálogo; descrição = especificações (só se vazia).
 *
 * O tipo do módulo vem exclusivamente de `customerApiProductType`. Nome de
 * produto e plan type servem apenas para a categoria de apresentação.
 *
 * Idempotente (find-or-create por `servergroup` × `servertype=bladecp` ×
 * `configoption2` = id do plano), **nunca deleta**, fail-soft (sem Capsule
 * → no-op). Os
 * Reutiliza `Pricing::allPlans()` e `Provisioning::serverGroup()`.
 */
final class PlanSync
{
    /** Sufixo de marca nas categorias (nomes default, editáveis no WHMCS). */
    public const BRAND = 'BladeCP';

    /**
     * `(productName, planType)` → categoria (grupo WHMCS) + tipo do
     * módulo (`configoption1`). Pura/determinística.
     *
     * @return array{group:string,type:string}
     */
    public static function categoryFor(string $productName, string $planType): array
    {
        $p = strtolower(trim($productName));
        $t = strtolower(trim($planType));

        // `order` = ordem canônica de exibição dos grupos na tela.
        return match (true) {
            $p === 'stack container' && $t === 'reseller'
                => ['group' => 'Revenda ' . self::BRAND, 'type' => 'stack-container', 'order' => 1],
            $p === 'stack container'
                => ['group' => 'Hospedagem ' . self::BRAND, 'type' => 'stack-container', 'order' => 0],
            $p === 'edge cloud'
                => ['group' => 'Servidores VPS ' . self::BRAND, 'type' => 'edge-cloud', 'order' => 2],
            $p === 'hyper cloud'
                => ['group' => 'Nuvem Pública ' . self::BRAND, 'type' => '', 'order' => 3],
            $p === 'quantum steel'
                => ['group' => 'Servidores Dedicados ' . self::BRAND, 'type' => 'dedicated', 'order' => 4],
            // Produto novo mantém uma categoria legível, mas não recebe
            // fallback de provisionamento.
            default => [
                'group' => ($productName !== '' ? $productName : 'Planos') . ' ' . self::BRAND,
                'type' => '',
                'order' => 9,
            ],
        };
    }

    /**
     * A semântica nativa "Require Domain" do WHMCS é o campo
     * `showdomainoptions`. Stack Container é hospedagem e precisa exibir a
     * etapa de domínio, enquanto Edge, Dedicated e tipos desconhecidos
     * preservam o comportamento atual do produto.
     *
     * @param array<string,mixed> $plan
     */
    public static function requiresDomain(array $plan): bool
    {
        return strtolower(trim((string) ($plan['type'] ?? '')))
            === 'stack-container';
    }

    /**
     * Descrição a partir dos dados técnicos do plano (subcontas/CPU/RAM/disco).
     * Pura; `''` se não houver dados. Texto simples separado por " · ".
     *
     * @param array<string,mixed> $plan
     */
    public static function describe(array $plan): string
    {
        $bits = [];
        $sub = (int) self::num($plan, ['subaccounts', 'subAccounts', 'subaccountsLimit', 'resellerAccounts']);
        if ($sub > 0) {
            $bits[] = $sub . ' subcontas';
        }
        $cpu = (int) self::num($plan, ['cpu', 'vcpu', 'vCpu', 'cpuCores']);
        if ($cpu > 0) {
            $bits[] = $cpu . ' vCPU';
        }
        $ram = self::num($plan, ['ramGb', 'ram', 'memoryGb', 'memory']);
        if ($ram > 0) {
            $bits[] = self::trimNum($ram) . ' GB RAM';
        }
        $disk = self::num($plan, ['diskGb', 'disk', 'storageGb', 'storage']);
        if ($disk > 0) {
            $bits[] = self::trimNum($disk) . ' GB de disco';
        }

        return implode(' · ', $bits);
    }

    private static function num(array $plan, array $keys): float
    {
        foreach ($keys as $k) {
            if (isset($plan[$k]) && is_numeric($plan[$k])) {
                return (float) $plan[$k];
            }
        }

        return 0.0;
    }

    /** Formata número tirando o `.0` (2.5 → "2.5", 4.0 → "4"). */
    private static function trimNum(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    // ── acesso ao WHMCS (fail-soft) ──

    /**
     * Planos normalizados para importação. Fail-soft `[]`.
     *
     * @return list<array<string,mixed>>
     */
    public static function plans(): array
    {
        return self::normalizePlans(Pricing::allPlans());
    }

    /** Planos do servidor explicitamente escolhido, sem trocar o contexto global. */
    public static function plansForServer(int $serverId): array
    {
        try {
            $client = ServerConfig::clientForServerId($serverId);
            if ($client === null) {
                return [];
            }
            $rows = $client->get('/partner/plans');
            if (isset($rows['data']) && is_array($rows['data'])) {
                $rows = $rows['data'];
            }
            return self::normalizePlans(is_array($rows) ? $rows : []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private static function normalizePlans(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $id = (string) $row['id'];
            $name = trim((string) ($row['name'] ?? '')) ?: ('Plano #' . $id);
            $productName = trim((string) ($row['productName'] ?? ''));
            $planType = trim((string) ($row['planType'] ?? ''));
            $cat = self::categoryFor($productName, $planType);
            $classification = PlanCatalog::classify($row);
            $sale = SaleGuard::decide($row);
            $supported = $classification['supported'] && $sale['allowed'];
            $out[] = [
                'id' => $id,
                'name' => $name,
                'productName' => $productName,
                'planType' => $planType,
                'group' => $cat['group'],
                'type' => $classification['type'],
                'order' => $cat['order'],
                'desc' => self::describe($row),
                'supported' => $supported,
                'structurallySupported' => $classification['supported'],
                'salesEligible' => $sale['allowed'],
                'reason' => $classification['reason'] ?? (
                    $sale['allowed'] ? null : $sale['reason']
                ),
                'availability' => $classification['availability'],
                'availabilityKnown' => $classification['availabilityKnown'],
                'legacyAvailability' => $classification['legacyAvailability'],
                'activationMode' => $classification['activationMode'],
                'requiresContract' => $classification['requiresContract'],
                'supportsContractTerms' => $classification['supportsContractTerms'],
                'contractTerms' => $classification['contractTerms'],
            ];
        }

        return $out;
    }

    /**
     * Mapa `id-do-plano => pid` dos produtos BladeCP já criados para o
     * servidor administrativo atual. O mesmo plan_id em outra conta não é
     * reutilizado. Fail-soft `[]`.
     *
     * @return array<string,int>
     */
    public static function existingByPlan(?int $serverGroupId = null): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }
        try {
            $scope = self::serverScope($serverGroupId);
            if ($scope === null) {
                return [];
            }
            $out = [];
            $query = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('configoption2', '!=', '');
            if ($scope['legacySingleServer']) {
                $query->whereIn('servergroup', [$scope['serverGroupId'], 0]);
            } else {
                $query->where('servergroup', $scope['serverGroupId']);
            }
            $rows = $query->get(['id', 'configoption2']);
            foreach ($rows as $r) {
                $plan = trim((string) ($r->configoption2 ?? ''));
                if ($plan !== '') {
                    $out[$plan] = (int) $r->id;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Grupo de produtos VISÍVEL (find-or-create) por nome → id, 0 fail-soft. */
    public static function productGroup(string $name, ?int $serverId = null): int
    {
        $name = trim($name);
        if ($name === '' || !class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            $scope = self::serverScope(null, $serverId);
            if ($scope === null) {
                return 0;
            }
            $name = self::scopedCategoryName($name, $scope);
            return CatalogSlugs::ensureGroup($name, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Importa um plano: find-or-create do grupo (categoria) + do produto.
     * Idempotente (não duplica) e nunca sobrescreve descrição existente.
     *
     * Produtos existentes são preservados mesmo quando o catálogo atual os
     * marca como não suportados. Apenas novos imports falham fechado.
     *
     * @param array<string,mixed> $plan
     * @return array{ok:bool,pid:int,mode:string,error:?string}
     */
    public static function importPlan(array $plan, ?int $comboId = null): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'pid' => 0, 'mode' => '', 'error' => 'Capsule indisponível.'];
        }
        $planId = trim((string) ($plan['id'] ?? ''));
        if ($planId === '') {
            return ['ok' => false, 'pid' => 0, 'mode' => 'skipped', 'error' => 'O catálogo não informou o id do plano.'];
        }
        $scope = self::serverScope();
        if ($scope === null) {
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'server-unavailable',
                'error' => 'Selecione um servidor BladeCP exclusivo antes de importar planos.',
            ];
        }
        $group = trim((string) ($plan['group'] ?? ''));
        try {
            $result = CatalogStateRepository::withImportLocks([
                'catalog-server:' . $scope['serverId'],
                'catalog-group:' . hash('sha256', $group),
                'catalog-plan:' . hash('sha256', $planId),
            ], static fn (): array => self::importPlanLocked($plan, $comboId, $scope));
        } catch (\Throwable $e) {
            Logger::logCall('CatalogImportFailed', [
                'plan_id' => $planId,
                'group' => $group,
            ], $e);
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'locked-failed',
                'error' => 'Não foi possível concluir a importação agora. Nenhuma alteração parcial foi salva. Tente novamente.',
            ];
        }
        if (!is_array($result)) {
            Logger::logCall('CatalogImportLockUnavailable', [
                'plan_id' => $planId,
                'group' => $group,
            ], ['error' => 'catalog import lock unavailable']);
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'locked-failed',
                'error' => 'Não foi possível obter o lock de importação do catálogo. Tente novamente.',
            ];
        }
        return $result;
    }

    /**
     * Executa a importação dentro do lock persistente de plano e categoria.
     * O lock é adquirido pelo método público antes de qualquer leitura ou
     * criação para impedir duplicidade entre requisições PHP concorrentes.
     *
     * @param array<string,mixed> $plan
     * @return array{ok:bool,pid:int,mode:string,error:?string}
     */
    private static function importPlanLocked(array $plan, ?int $comboId, array $scope): array
    {
        $planId = trim((string) ($plan['id'] ?? ''));
        $existingQuery = \WHMCS\Database\Capsule::table('tblproducts')
            ->where('servertype', 'bladecp')
            ->where('configoption2', $planId)
            ->orderBy('id')
            ->lockForUpdate();
        if ($scope['legacySingleServer']) {
            $existingQuery->whereIn('servergroup', [$scope['serverGroupId'], 0]);
        } else {
            $existingQuery->where('servergroup', $scope['serverGroupId']);
        }
        $existing = $existingQuery->get(['id', 'gid']);
        if (!$existing->isEmpty()) {
            $existingProduct = $existing->first();
            $pid = (int) $existingProduct->id;
            $existingGroupId = (int) ($existingProduct->gid ?? 0);
            self::ensureDomainRequirement($pid, (string) ($plan['type'] ?? ''));
            CatalogSlugs::reconcileProductRoute($pid, $existingGroupId > 0 ? $existingGroupId : null, (string) ($plan['name'] ?? ''));
            CatalogAvailabilitySync::reconcileProduct(
                $pid,
                $planId,
                $plan
            );
            if (($plan['supported'] ?? false) !== true) {
                return [
                    'ok' => true,
                    'pid' => $pid,
                    'mode' => 'blocked-existing',
                    'error' => null,
                ];
            }
            $locationReady = self::ensureLocationOptions($planId, $pid);
            $contractReady = self::ensureContractOptions($planId, $pid, $plan);
            if (!$locationReady || !$contractReady) {
                return [
                    'ok' => false,
                    'pid' => $pid,
                    'mode' => 'existing-options-failed',
                    'error' => 'O produto existente foi preservado, mas suas opções operacionais não puderam ser atualizadas.',
                ];
            }
            return ['ok' => true, 'pid' => $pid, 'mode' => 'exists', 'error' => null];
        }
        if (($plan['supported'] ?? false) !== true) {
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'skipped',
                'error' => (string) ($plan['reason'] ?? 'Plano indisponível para este módulo.'),
            ];
        }
        if (!ctype_digit($planId) || (int) $planId <= 0) {
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'skipped',
                'error' => 'O catálogo não informou um id numérico válido para o plano.',
            ];
        }
        $locations = self::locationsForPlan($planId);
        if ($locations === []) {
            return [
                'ok' => false,
                'pid' => 0,
                'mode' => 'skipped',
                'error' => 'O plano não possui location ativa e vinculada.',
            ];
        }
        $groupId = self::productGroup((string) ($plan['group'] ?? ''), $scope['serverId']);
        if ($groupId <= 0) {
            throw new \RuntimeException('Não foi possível criar a categoria do produto.');
        }
        $cols = array_flip(
            \WHMCS\Database\Capsule::schema()->getColumnListing('tblproducts')
        );
        $pid = (int) \WHMCS\Database\Capsule::table('tblproducts')
            ->insertGetId(array_intersect_key([
                'type' => 'hostingaccount',
                'gid' => $groupId,
                'name' => (string) ($plan['name'] ?? ''),
                'description' => (string) ($plan['desc'] ?? ''),
                'hidden' => 0,
                'showdomainoptions' => self::requiresDomain($plan) ? 1 : 0,
                'paytype' => 'recurring',
                'servertype' => 'bladecp',
                'servergroup' => $scope['serverGroupId'],
                'configoption1' => (string) ($plan['type'] ?? ''),
                'configoption2' => $planId,
                'autosetup' => 'order',
                'order' => ctype_digit($planId) ? (int) $planId : 0,
            ], $cols));
        if ($pid <= 0) {
            throw new \RuntimeException('Não foi possível criar o produto.');
        }
        CatalogSlugs::reconcileProductRoute($pid, $groupId, (string) ($plan['name'] ?? ''));

        if ($comboId !== null && $comboId > 0) {
            Combos::assignProduct($pid, $comboId);
            if (Combos::comboIdForProduct($pid) !== $comboId) {
                throw new \RuntimeException('Não foi possível atribuir o combo ao produto.');
            }
        } else {
            $gid = ConfigurableOptions::groupId(false);
            if ($gid !== null) {
                ConfigurableOptions::linkProduct($gid, $pid);
            }
        }
        if (!LocationOptions::apply($planId, $pid, $locations)) {
            throw new \RuntimeException('Não foi possível materializar as locations do plano.');
        }
        if (!self::ensureContractOptions($planId, $pid, $plan)) {
            throw new \RuntimeException('Não foi possível materializar as opções contratuais.');
        }
        CatalogAvailabilitySync::reconcileProduct(
            $pid,
            $planId,
            $plan
        );

        return ['ok' => true, 'pid' => $pid, 'mode' => 'created', 'error' => null];
    }

    /**
     * @return array{serverId:int,serverGroupId:int,legacySingleServer:bool}|null
     */
    private static function serverScope(?int $serverGroupId = null, ?int $serverId = null): ?array
    {
        try {
            $context = AdminServerContext::resolve();
            $resolvedId = $serverId ?? (int) ($context['serverId'] ?? 0);
            $explicitCandidate = false;
            if ($serverId !== null && $serverId > 0) {
                foreach ($context['candidates'] ?? [] as $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $serverId) {
                        $explicitCandidate = true;
                        break;
                    }
                }
            }
            if ($resolvedId <= 0 || ($serverId === null && ($context['state'] ?? '') !== 'configured') || ($serverId !== null && !$explicitCandidate)) {
                return null;
            }
            $groupId = $serverGroupId ?? Provisioning::serverGroup($resolvedId);
            if ($groupId <= 0) {
                return null;
            }
            return [
                'serverId' => $resolvedId,
                'serverGroupId' => $groupId,
                'legacySingleServer' => count($context['candidates'] ?? []) === 1,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{serverId:int,serverGroupId:int,legacySingleServer:bool} $scope */
    private static function scopedCategoryName(string $name, array $scope): string
    {
        return $scope['legacySingleServer']
            ? $name
            : $name . ' · Servidor #' . $scope['serverId'];
    }

    /**
     * Importa todos os planos ainda não criados no WHMCS.
     *
     * @return array{
     *   created:int,
     *   exists:int,
     *   failed:int,
     *   skipped:int,
     *   pids:list<int>,
     *   ignored:list<array{id:string,name:string,reason:string}>
     * }
     */
    public static function importMissing(?int $comboId = null): array
    {
        $created = 0;
        $exists = 0;
        $failed = 0;
        $skipped = 0;
        $pids = [];
        $ignored = [];
        foreach (self::plans() as $plan) {
            $r = self::importPlan($plan, $comboId);
            if (!$r['ok']) {
                if ($r['mode'] === 'skipped') {
                    $skipped++;
                    $ignored[] = [
                        'id' => (string) ($plan['id'] ?? ''),
                        'name' => (string) ($plan['name'] ?? ''),
                        'reason' => (string) ($r['error'] ?? ''),
                    ];
                } else {
                    $failed++;
                }
            } elseif ($r['mode'] === 'created') {
                $created++;
                $pids[] = $r['pid'];
            } else {
                $exists++;
            }
        }

        return [
            'created' => $created,
            'exists' => $exists,
            'failed' => $failed,
            'skipped' => $skipped,
            'pids' => $pids,
            'ignored' => $ignored,
        ];
    }

    /**
     * Reordena os produtos BladeCP pelo id do plano (`configoption2`) →
     * a vitrine mostra os planos em ordem natural dos seus identificadores, em
     * vez da ordem de importação. Corrige produtos antigos que entraram sem
     * `order`. Idempotente, fail-soft. Retorna quantos reordenou.
     */
    public static function reorder(): int
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return 0;
        }
        try {
            $n = 0;
            $rows = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->where('configoption2', '!=', '')
                ->get(['id', 'configoption2']);
            foreach ($rows as $r) {
                $plan = trim((string) ($r->configoption2 ?? ''));
                if ($plan === '' || !ctype_digit($plan)) {
                    continue;
                }
                \WHMCS\Database\Capsule::table('tblproducts')
                    ->where('id', (int) $r->id)
                    ->update(['order' => (int) $plan]);
                $n++;
            }

            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Atualiza o estado comercial de todos os produtos sem apagar nem
     * reexpor automaticamente nenhum item.
     *
     * @return array{ok:bool,processed:int,hidden:int,blocked:int,reason:string}
     */
    public static function reconcileAvailability(): array
    {
        $scope = self::serverScope();
        return $scope === null
            ? ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'server-unavailable']
            : CatalogAvailabilitySync::reconcileCatalog(
                self::plans(),
                $scope['serverId'],
                $scope['serverGroupId']
            );
    }

    /** Reconciliador do cron, sempre escopado a um server group exclusivo. */
    public static function reconcileAvailabilityForServer(int $serverId): array
    {
        $scope = self::serverScope(null, $serverId);
        return $scope === null
            ? ['ok' => false, 'processed' => 0, 'hidden' => 0, 'blocked' => 0, 'reason' => 'server-unavailable']
            : CatalogAvailabilitySync::reconcileCatalog(
                self::plansForServer($serverId),
                $scope['serverId'],
                $scope['serverGroupId']
            );
    }

    /**
     * Vincula o grupo de Configurable Options "BladeCP" (componentes/
     * combos) a todos os produtos BladeCP ainda sem vínculo. Requer que
     * os componentes já tenham sido sincronizados (aba Componentes).
     * Idempotente (usa `linkProduct`, que não duplica), fail-soft.
     *
     * @return array{ok:bool,linked:int,reason:?string}
     */
    public static function linkComponents(): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'linked' => 0, 'reason' => 'no-capsule'];
        }
        try {
            $gid = ConfigurableOptions::groupId(false);
            if ($gid === null) {
                return ['ok' => false, 'linked' => 0, 'reason' => 'no-group'];
            }
            $already = ConfigurableOptions::linkedProductIds();
            $n = 0;
            $rows = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->get(['id', 'configoption1', 'configoption2']);
            foreach ($rows as $r) {
                $pid = (int) $r->id;
                if ($pid > 0 && !isset($already[$pid])) {
                    ConfigurableOptions::linkProduct($gid, $pid);
                    $n++;
                }
                $planId = trim((string) ($r->configoption2 ?? ''));
                if ($planId !== '') {
                    self::ensureLocationOptions($planId, $pid);
                    self::ensureContractOptions($planId, $pid);
                }
            }

            return ['ok' => true, 'linked' => $n, 'reason' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'linked' => 0, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Aplica o MODO a TODOS os produtos BladeCP já existentes (não só
     * aos novos imports). Simples (combo id): atribui o combo fixo +
     * DESVINCULA as opções do carrinho. Composável (null): vincula as
     * opções. Idempotente, fail-soft. Retorna quantos produtos tocou.
     *
     * @return array{ok:bool,affected:int,reason:?string}
     */
    public static function applyMode(?int $comboId): array
    {
        if (!class_exists(\WHMCS\Database\Capsule::class)) {
            return ['ok' => false, 'affected' => 0, 'reason' => 'no-capsule'];
        }
        try {
            $gid = ConfigurableOptions::groupId(false);
            $n = 0;
            $rows = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('servertype', 'bladecp')
                ->get(['id', 'configoption1', 'configoption2']);
            foreach ($rows as $r) {
                $pid = (int) $r->id;
                if ($pid <= 0) {
                    continue;
                }
                self::ensureDomainRequirement(
                    $pid,
                    (string) ($r->configoption1 ?? '')
                );
                $planId = trim((string) ($r->configoption2 ?? ''));
                if ($planId !== '') {
                    self::ensureLocationOptions($planId, $pid);
                    self::ensureContractOptions($planId, $pid);
                }
                if ($comboId !== null && $comboId > 0) {
                    Combos::assignProduct($pid, $comboId);
                    if ($gid !== null) {
                        ConfigurableOptions::unlinkProduct($gid, $pid);
                    }
                } elseif ($gid !== null) {
                    ConfigurableOptions::linkProduct($gid, $pid);
                }
                $n++;
            }

            return ['ok' => true, 'affected' => $n, 'reason' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'affected' => 0, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Normaliza Require Domain para produto BladeCP já importado. Stack
     * Container exige a etapa de domínio; Edge, Dedicated e tipos
     * desconhecidos não devem manter uma flag obsoleta se o catálogo mudou.
     * A condição no UPDATE torna a reconciliação idempotente.
     */
    private static function ensureDomainRequirement(int $productId, string $productType): void
    {
        if (
            $productId <= 0
            || !class_exists(\WHMCS\Database\Capsule::class)
        ) {
            return;
        }
        try {
            $required = strtolower(trim($productType)) === 'stack-container' ? 1 : 0;
            $columns = array_flip(
                \WHMCS\Database\Capsule::schema()->getColumnListing('tblproducts')
            );
            if (!isset($columns['showdomainoptions'])) {
                return;
            }
            \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->where('showdomainoptions', '!=', $required)
                ->update(['showdomainoptions' => $required]);
        } catch (\Throwable) {
            // A importação não pode falhar se uma instalação antiga não
            // expuser esse campo nativo.
        }
    }

    /**
     * Garante o grupo obrigatório de locations do plano, sem remover o estado
     * existente quando catálogo ou credencial estão indisponíveis.
     */
    private static function ensureLocationOptions(string $planId, int $pid): bool
    {
        if ($pid <= 0 || !ctype_digit($planId) || (int) $planId <= 0) {
            return false;
        }
        try {
            $locations = self::locationsForPlan($planId);
            return LocationOptions::apply($planId, $pid, $locations);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,string> */
    private static function locationsForPlan(string $planId): array
    {
        try {
            $client = ServerConfig::client();
            if ($client === null) {
                return [];
            }
            return Loaders::locations($client, $planId);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed>|null $normalizedPlan */
    private static function ensureContractOptions(
        string $planId,
        int $pid,
        ?array $normalizedPlan = null
    ): bool {
        if ($pid <= 0 || !ctype_digit($planId)) {
            return false;
        }
        if ($normalizedPlan === null) {
            foreach (self::plans() as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $planId) {
                    $normalizedPlan = $candidate;
                    break;
                }
            }
        }
        if ($normalizedPlan === null) {
            return true;
        }
        $terms = is_array($normalizedPlan['contractTerms'] ?? null)
            ? $normalizedPlan['contractTerms']
            : [];
        $requires = ($normalizedPlan['requiresContract'] ?? false) === true;
        if ($terms === []) {
            return !$requires;
        }
        return ContractOptions::apply($planId, $pid, $terms, $requires);
    }
}
