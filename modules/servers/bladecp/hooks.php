<?php

/**
 * Hooks do WHMCS.
 *
 * Personaliza a tela de configuração do produto com CSS e JavaScript
 * escopados. Os hooks só atuam nas telas aplicáveis e não dependem de
 * serviços externos.
 *
 * `hooks.php` é entrypoint separado do WHMCS — NÃO depende da ordem
 * de load do bladecp.php (nem o re-inclui: re-declararia funções).
 * Bootstrap PSR-4 próprio, idempotente e independente do bladecp.php.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerServerAutoloader(__DIR__);

use WHMCS\Module\Server\Bladecp\Ui\ConfigSkin;
use WHMCS\Module\Server\Bladecp\Ui\OrderFormSkin;
use WHMCS\Module\Server\Bladecp\Helpers\CartGuard;

if (function_exists('add_hook')) {
    $bladecpGuardFallback = static function (array $productIds): array {
        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($productIds === [] || !class_exists(\WHMCS\Database\Capsule::class)) {
            return [];
        }
        try {
            $matched = \WHMCS\Database\Capsule::table('tblproducts')
                ->whereIn('id', $productIds)
                ->where('servertype', 'bladecp')
                ->count();
            return $matched > 0
                ? ['Este produto BladeCP não pôde ter sua disponibilidade confirmada. Nenhuma cobrança ou provisão será iniciada.']
                : [];
        } catch (\Throwable) {
            return [];
        }
    };

    /**
     * Barreiras para novas vendas. A política, snapshot e fail-closed vivem
     * em SaleGuard, acessado somente pelo adaptador CartGuard. Produtos de
     * outros módulos e serviços já existentes ficam fora deste caminho.
     */
    add_hook('ShoppingCartValidateProductUpdate', 1, static function ($vars) use ($bladecpGuardFallback): array {
        $input = is_array($vars) ? $vars : [];
        try {
            $error = CartGuard::errorForProductUpdate($input);
            return $error === null ? [] : [$error];
        } catch (\Throwable) {
            $ids = [];
            $index = isset($input['i']) ? (int) $input['i'] : -1;
            $items = $_SESSION['cart']['products'] ?? [];
            if ($index >= 0 && is_array($items) && isset($items[$index]) && is_array($items[$index])) {
                $ids[] = (int) ($items[$index]['pid'] ?? 0);
            }
            foreach (['pid', 'productid', 'newproductid', 'newpid'] as $key) {
                $ids[] = (int) ($input[$key] ?? 0);
            }
            return $bladecpGuardFallback($ids);
        }
    });

    add_hook('ShoppingCartValidateCheckout', 1, static function ($vars) use ($bladecpGuardFallback): array {
        try {
            return CartGuard::checkoutErrors();
        } catch (\Throwable) {
            $ids = [];
            foreach (is_array($_SESSION['cart']['products'] ?? null) ? $_SESSION['cart']['products'] : [] as $item) {
                if (is_array($item)) {
                    $ids[] = (int) ($item['pid'] ?? 0);
                }
            }
            return $bladecpGuardFallback($ids);
        }
    });

    add_hook('ShoppingCartValidateUpgrade', 1, static function ($vars) use ($bladecpGuardFallback): array {
        $input = is_array($vars) ? $vars : [];
        try {
            $error = CartGuard::errorForUpgrade($input);
            return $error === null ? [] : [$error];
        } catch (\Throwable) {
            $ids = [];
            foreach (['newproductid', 'pid', 'productid', 'newpid'] as $key) {
                $ids[] = (int) ($input[$key] ?? 0);
            }
            return $bladecpGuardFallback($ids);
        }
    });

    // Aprimoramento progressivo do formulário do cliente: o combo
    // dirige/trava os selects. Allowlist estrita de ids do nosso
    // grupo + self-gating + fail-soft. NÃO toca
    // markup nativo nem o Configure Product de outros produtos.
    OrderFormSkin::registerHook();

    add_hook('AdminAreaHeadOutput', 1, static function ($vars): string {
        try {
            return ConfigSkin::headOutput();
        } catch (\Throwable) {
            return '';
        }
    });

    add_hook('AdminAreaFooterOutput', 1, static function ($vars): string {
        try {
            return ConfigSkin::footerOutput();
        } catch (\Throwable) {
            return '';
        }
    });
}
