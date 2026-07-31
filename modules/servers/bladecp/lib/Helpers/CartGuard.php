<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Helpers;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Adaptador de venda para os hooks nativos do carrinho.
 *
 * A política pertence a SaleGuard. Esta classe conhece somente o produto
 * WHMCS, reduz a decisão para uma mensagem segura e mantém um cache curto
 * por request para que checkout com itens repetidos não reavalie o plano.
 */
final class CartGuard
{
    private const UNAVAILABLE_MESSAGE = 'Este produto está temporariamente indisponível porque sua automação ainda não foi liberada. Nenhuma cobrança ou provisão será iniciada.';

    /** @var array<int, string|null> */
    private static array $resultCache = [];

    /**
     * Retorna null quando a venda pode continuar ou quando o id não é BladeCP.
     *
     * Para produto BladeCP confirmado, uma ausência ou falha da decisão fecha
     * a nova venda. Serviços existentes não passam pelos hooks de carrinho.
     */
    public static function errorForProductId(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        if (array_key_exists($productId, self::$resultCache)) {
            return self::$resultCache[$productId];
        }

        $isBladecp = false;
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return self::$resultCache[$productId] = null;
            }

            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->select(['id', 'servertype'])
                ->where('id', $productId)
                ->first();

            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp') {
                return self::$resultCache[$productId] = null;
            }
            $isBladecp = true;

            if (!class_exists(SaleGuard::class) || !method_exists(SaleGuard::class, 'checkProductId')) {
                return self::$resultCache[$productId] = self::UNAVAILABLE_MESSAGE;
            }

            /** @var array<string,mixed>|null $decision */
            $decision = SaleGuard::checkProductId($productId);
            if ($decision === null || empty($decision['eligible'])) {
                return self::$resultCache[$productId] = self::UNAVAILABLE_MESSAGE;
            }

            return self::$resultCache[$productId] = null;
        } catch (\Throwable) {
            // Só há fail-closed depois de identificar com segurança o produto
            // BladeCP. Não registrar payload, credencial ou decisão remota.
            return self::$resultCache[$productId] = $isBladecp
                ? self::UNAVAILABLE_MESSAGE
                : null;
        }
    }

    /** @param array<string,mixed> $vars */
    public static function errorForProductUpdate(array $vars): ?string
    {
        $index = isset($vars['i']) ? (int) $vars['i'] : -1;
        $items = $_SESSION['cart']['products'] ?? [];
        if ($index >= 0 && is_array($items) && isset($items[$index]) && is_array($items[$index])) {
            return self::errorForProductId((int) ($items[$index]['pid'] ?? 0));
        }

        return self::errorForProductId(self::productIdFromVars($vars));
    }

    /** @param array<string,mixed> $vars */
    public static function errorForUpgrade(array $vars): ?string
    {
        foreach (['newproductid', 'pid', 'productid', 'newpid'] as $key) {
            if (isset($vars[$key]) && (int) $vars[$key] > 0) {
                return self::errorForProductId((int) $vars[$key]);
            }
        }

        return null;
    }

    /** @return array<int,string> */
    public static function checkoutErrors(): array
    {
        $items = $_SESSION['cart']['products'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $errors = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $error = self::errorForProductId((int) ($item['pid'] ?? 0));
            if ($error !== null) {
                $errors[$error] = $error;
            }
        }

        return array_values($errors);
    }

    /** @param array<string,mixed> $vars */
    private static function productIdFromVars(array $vars): int
    {
        foreach (['pid', 'productid', 'newproductid', 'newpid'] as $key) {
            if (isset($vars[$key]) && (int) $vars[$key] > 0) {
                return (int) $vars[$key];
            }
        }

        return 0;
    }
}
