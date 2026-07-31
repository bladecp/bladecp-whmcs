<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

use WHMCS\Module\Server\Bladecp\Api\CatalogSnapshot;
use WHMCS\Module\Server\Bladecp\Api\Client;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Reconsulta a disponibilidade imediatamente antes de CreateAccount.
 * A chamada é intencionalmente independente do catálogo exibido no admin:
 * uma tela antiga não pode autorizar uma nova provisão.
 */
final class ProvisionPreflight
{
    /**
     * @return array{allowed:bool,message:string,plan:?array<string,mixed>,availability:?array<string,mixed>}
     */
    public static function check(
        Client $client,
        int|string $planId,
        int $productId = 0
    ): array
    {
        if ($productId > 0) {
            $local = SaleGuard::checkLocalProductId($productId);
            if ($local !== null) {
                return [
                    'allowed' => false,
                    'message' => 'BladeCP: ' . $local['reason'],
                    'plan' => null,
                    'availability' => $local['availability'],
                ];
            }
        }
        $snapshot = CatalogSnapshot::fetch($client, true);
        if (!$snapshot['ok']) {
            return [
                'allowed' => false,
                'message' => 'BladeCP: não foi possível confirmar a disponibilidade deste plano antes de provisionar. Tente novamente em alguns minutos.',
                'plan' => null,
                'availability' => null,
            ];
        }
        $plan = CatalogSnapshot::plan($snapshot, $planId);
        if ($plan === null) {
            return [
                'allowed' => false,
                'message' => 'BladeCP: este plano não está mais disponível no catálogo atual.',
                'plan' => null,
                'availability' => null,
            ];
        }
        $decision = SaleGuard::decide($plan);
        return [
            'allowed' => $decision['allowed'],
            'message' => $decision['allowed']
                ? ''
                : SaleGuard::message($decision),
            'plan' => $plan,
            'availability' => $decision['availability'],
        ];
    }
}
