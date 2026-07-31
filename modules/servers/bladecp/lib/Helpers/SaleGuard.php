<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\CatalogSnapshot;
use WHMCS\Module\Server\Bladecp\Api\PlanAvailability;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Decisão local de venda, sempre limitada pelo teto da Customer API. */
final class SaleGuard
{
    /**
     * @param array<string,mixed> $plan
     * @return array{allowed:bool,reason:string,availability:array<string,mixed>}
     */
    public static function decide(array $plan): array
    {
        $availability = PlanAvailability::fromPlan($plan);
        $allowed = ($availability['salesEligible'] ?? false) === true
            && PlanAvailability::isFresh($availability);

        return [
            'allowed' => $allowed,
            'reason' => $allowed
                ? ''
                : PlanAvailability::explain($availability),
            'availability' => $availability,
        ];
    }

    /** @param array<string,mixed> $decision */
    public static function message(array $decision): string
    {
        $reason = trim((string) ($decision['reason'] ?? ''));
        return $reason === ''
            ? 'BladeCP: este plano está disponível para venda.'
            : 'BladeCP: ' . $reason;
    }

    /**
     * Consulta segura para hooks de carrinho. `null` significa somente que
     * não foi possível identificar o produto como BladeCP neste contexto;
     * um produto BladeCP jamais recebe `null` por falha de catálogo.
     *
     * @return array{eligible:bool,reason:string,code:string,stale:bool,availability:?array<string,mixed>}|null
     */
    public static function checkProductId(int $productId): ?array
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first(['servertype', 'configoption2', 'hidden']);
        } catch (\Throwable) {
            return null;
        }
        if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp') {
            return null;
        }
        if ((int) ($product->hidden ?? 0) === 1) {
            return self::blocked(
                'product_hidden',
                'Este produto não está liberado para novas vendas.',
                false,
                null
            );
        }

        $planId = trim((string) ($product->configoption2 ?? ''));
        if ($planId === '') {
            return self::blocked('plan_not_configured', 'Este produto BladeCP não possui um plano configurado.', false, null);
        }
        $local = self::localDecision($productId);
        if ($local !== null && !$local['eligible']) {
            return self::blocked(
                $local['code'],
                $local['reason'],
                $local['stale'],
                $local['availability']
            );
        }
        $client = ServerConfig::client();
        if ($client === null) {
            return self::blocked('availability_unknown', 'Não foi possível confirmar a disponibilidade deste produto BladeCP agora.', true, null);
        }
        $snapshot = CatalogSnapshot::fetch($client);
        if (!$snapshot['ok']) {
            return self::blocked('availability_unknown', 'Não foi possível confirmar a disponibilidade deste produto BladeCP agora.', true, null);
        }
        $plan = CatalogSnapshot::plan($snapshot, $planId);
        if ($plan === null) {
            return self::blocked('plan_not_available', 'Este plano não está disponível no catálogo BladeCP atual.', false, null);
        }
        $decision = self::decide($plan);
        $availability = $decision['availability'];
        $stale = !PlanAvailability::isFresh($availability);
        $blockers = $availability['blockers'] ?? [];
        $code = is_array($blockers) && isset($blockers[0]) && is_array($blockers[0])
            ? trim((string) ($blockers[0]['code'] ?? ''))
            : '';
        if ($code === '') {
            $code = $stale ? 'availability_stale' : 'availability_not_eligible';
        }

        return [
            'eligible' => $decision['allowed'],
            'reason' => $decision['allowed'] ? '' : $decision['reason'],
            'code' => $code,
            'stale' => $stale,
            'availability' => $availability,
        ];
    }

    /**
     * Verifica somente os tetos locais antes do preflight remoto. `null`
     * significa que o produto pode seguir para a confirmação autoritativa.
     *
     * @return array{eligible:bool,reason:string,code:string,stale:bool,availability:?array<string,mixed>}|null
     */
    public static function checkLocalProductId(int $productId): ?array
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return null;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first(['servertype', 'hidden']);
        } catch (\Throwable) {
            return null;
        }
        if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp') {
            return null;
        }
        if ((int) ($product->hidden ?? 0) === 1) {
            return self::blocked(
                'product_hidden',
                'Este produto não está liberado para novas provisões.',
                false,
                null
            );
        }
        $local = self::localDecision($productId);
        if ($local === null || $local['eligible']) {
            return null;
        }
        return self::blocked(
            $local['code'],
            $local['reason'],
            $local['stale'],
            $local['availability']
        );
    }

    /**
     * @return array{eligible:false,reason:string,code:string,stale:bool,availability:null}
     */
    private static function blocked(
        string $code,
        string $reason,
        bool $stale,
        ?array $availability
    ): array {
        return [
            'eligible' => false,
            'reason' => $reason,
            'code' => $code,
            'stale' => $stale,
            'availability' => $availability,
        ];
    }

    /**
     * Aplica o teto persistido pelo addon sem criar uma dependência de
     * autoload entre os dois módulos. A tabela é opcional e read-only aqui.
     *
     * @return array{eligible:bool,reason:string,code:string,stale:bool,availability:?array<string,mixed>}|null
     */
    private static function localDecision(int $productId): ?array
    {
        try {
            $schema = \WHMCS\Database\Capsule::schema();
            if (!$schema->hasTable('mod_bladecp_catalog_product_state')) {
                return null;
            }
            $row = \WHMCS\Database\Capsule::table(
                'mod_bladecp_catalog_product_state'
            )->where('product_id', $productId)->first();
            if ($row === null || (int) ($row->effective_sales_eligible ?? 0) === 1) {
                return null;
            }
            $validUntil = trim((string) ($row->valid_until ?? ''));
            $stale = $validUntil !== ''
                && (($until = strtotime($validUntil)) === false || $until < time());
            $reason = trim((string) ($row->effective_reason ?? ''));
            $code = $reason !== '' ? str_replace('-', '_', $reason) : 'local_sale_blocked';

            return [
                'eligible' => false,
                'reason' => $stale
                    ? 'A confirmação local deste produto expirou. Atualize o catálogo antes de continuar.'
                    : 'Este produto aguarda revisão e liberação no módulo BladeCP.',
                'code' => $code,
                'stale' => $stale,
                'availability' => null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
