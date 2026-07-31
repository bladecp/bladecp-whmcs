<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Router;

use WHMCS\Module\Server\Bladecp\Helpers\ProductType;
use WHMCS\Module\Server\Bladecp\Helpers\ProductCapability;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Roteador por discriminador. Despacha (função WHMCS × product
 * type) pra classe certa em Functions\{Stack,Edge,Dedicated}\. Funções
 * Shared (TestConnection/ListAccounts/UsageUpdate) não bifurcam.
 *
 * Stack Container, Edge Cloud e Dedicated possuem lifecycle explícito. Vibe é
 * reconhecido para compatibilidade de configurações, mas seus serviços não
 * são provisionados por este módulo.
 */
final class ProductTypeRouter
{
    private const SHARED = [
        'TestConnection' => \WHMCS\Module\Server\Bladecp\Functions\Shared\TestConnection::class,
        'ListAccounts' => \WHMCS\Module\Server\Bladecp\Functions\Shared\ListAccounts::class,
        'UsageUpdate' => \WHMCS\Module\Server\Bladecp\Functions\Shared\UsageUpdate::class,
    ];

    /** @return mixed o que a Function retorna (string/array, contrato WHMCS) */
    public static function dispatch(string $function, array $params): mixed
    {
        if (isset(self::SHARED[$function])) {
            return self::SHARED[$function]::run($params);
        }

        $type = ProductType::getFromParams($params);
        $class = ProductCapability::handlerClass($type, $function);
        if (!class_exists($class)) {
            throw new \RuntimeException(
                "BladeCP: handler configurado para '{$function}' e '{$type}' não foi encontrado."
            );
        }

        return $class::run($params);
    }
}
