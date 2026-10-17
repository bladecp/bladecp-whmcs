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
                return self::$resultCache[$productId] = ClientText::get('cart_unavailable');
            }

            /** @var array<string,mixed>|null $decision */
            $decision = SaleGuard::checkProductId($productId);
            if ($decision === null || empty($decision['eligible'])) {
                return self::$resultCache[$productId] = ClientText::get('cart_unavailable');
            }

            return self::$resultCache[$productId] = null;
        } catch (\Throwable) {
            // Só há fail-closed depois de identificar com segurança o produto
            // BladeCP. Não registrar payload, credencial ou decisão remota.
            return self::$resultCache[$productId] = $isBladecp
                ? ClientText::get('cart_unavailable')
                : null;
        }
    }

    /** @param array<string,mixed> $vars */
    public static function errorForProductUpdate(array $vars): ?string
    {
        $index = isset($vars['i']) ? (int) $vars['i'] : -1;
        $items = $_SESSION['cart']['products'] ?? [];
        if ($index >= 0 && is_array($items) && isset($items[$index]) && is_array($items[$index])) {
            $productId = (int) ($items[$index]['pid'] ?? 0);
            $options = self::postedConfigOptions($vars)
                ?? (is_array($items[$index]['configoptions'] ?? null) ? $items[$index]['configoptions'] : []);
            return self::freeGuard($productId)
                ?? self::errorForProductId($productId)
                ?? self::componentError($productId, $options);
        }

        $productId = self::productIdFromVars($vars);
        return self::freeGuard($productId)
            ?? self::errorForProductId($productId)
            ?? self::componentError($productId, self::postedConfigOptions($vars) ?? []);
    }

    /** @param array<string,mixed> $vars */
    public static function errorForUpgrade(array $vars): ?string
    {
        $serviceId = self::positiveInteger($vars['id'] ?? $vars['serviceid'] ?? $vars['service_id'] ?? null);
        $productId = self::positiveInteger($vars['pid'] ?? $vars['newproductid'] ?? $vars['newpid'] ?? $vars['productid'] ?? null);
        $billingCycle = self::postedBillingCycle();
        $nonce = self::postedCommercialNonce();
        $commercialMatch = CommercialUpgradeResume::consumeMatch(
            $serviceId,
            $productId,
            CommercialUpgradeResume::currentClientId() ?? 0,
            $billingCycle,
            $nonce
        );
        if ($commercialMatch === false || ($commercialMatch === null && $nonce !== '')) {
            return ClientText::get('cart_offer_expired');
        }
        $upgradeVars = $vars;
        if ($serviceId > 0) {
            $upgradeVars['serviceid'] = $serviceId;
        }
        if ($productId > 0) {
            $upgradeVars['pid'] = $productId;
        }
        $vibeError = VibeUpgradeGuard::errorForUpgrade($upgradeVars);
        if ($vibeError !== null) {
            return $vibeError;
        }
        if ($productId > 0) {
            return self::errorForProductId($productId);
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
            $productId = (int) ($item['pid'] ?? 0);
            if (self::isFreeVibeProduct($productId)) {
                $errors[ClientText::get('cart_free_direct')] = ClientText::get('cart_free_direct');
                continue;
            }
            $error = self::errorForProductId($productId)
                ?? self::componentError(
                    $productId,
                    is_array($item['configoptions'] ?? null) ? $item['configoptions'] : []
                );
            if ($error !== null) {
                $errors[$error] = $error;
            }
        }
        return array_values($errors);
    }

    /**
     * Components chosen for a BladeCP product must still be in the catalog.
     * Runs only after the product itself was confirmed as sellable.
     *
     * @param array<int|string,mixed> $configoptions configid => sub-option id
     */
    private static function componentError(int $productId, array $configoptions): ?string
    {
        if ($productId <= 0 || !class_exists(ComponentAvailability::class)) {
            return null;
        }
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return null;
            }
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->select(['id', 'servertype'])
                ->where('id', $productId)
                ->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp') {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        try {
            return ComponentAvailability::errorForCartItem($productId, $configoptions);
        } catch (\Throwable) {
            return ClientText::get('cart_components_unconfirmed');
        }
    }

    /**
     * Configurable options posted with the product configuration form, when
     * present (configid => sub-option id).
     *
     * @param array<string,mixed> $vars
     * @return array<int|string,mixed>|null
     */
    private static function postedConfigOptions(array $vars): ?array
    {
        foreach ([$vars['configoption'] ?? null, $vars['configoptions'] ?? null, $_POST['configoption'] ?? null] as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function freeGuard(int $productId): ?string
    {
        if (!self::isFreeVibeProduct($productId)) {
            return null;
        }
        return ClientText::get('cart_free_direct');
    }

    private static function isFreeVibeProduct(int $productId): bool
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $productId)
                ->first();
            return $product !== null
                && (string) ($product->servertype ?? '') === 'bladecp'
                && strtolower(trim((string) ($product->configoption1 ?? ''))) === 'vibe'
                && strtolower(trim((string) ($product->configoption4 ?? ''))) === VibePlanTier::FREE
                && strtolower(trim((string) ($product->paytype ?? ''))) === 'free';
        } catch (\Throwable) {
            return false;
        }
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

    private static function postedBillingCycle(): string
    {
        $billingCycle = $_POST['billingcycle'] ?? null;
        if (!is_string($billingCycle)) {
            return '';
        }
        return in_array($billingCycle, ['monthly', 'quarterly', 'semiannually', 'annually'], true)
            ? $billingCycle
            : '';
    }

    private static function postedCommercialNonce(): string
    {
        $nonce = $_POST['bladecp_commercial_nonce'] ?? null;
        return is_string($nonce) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce) === 1
            ? $nonce
            : '';
    }

    private static function positiveInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return 0;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        return $integer !== false && $integer > 0 ? (int) $integer : 0;
    }
}
