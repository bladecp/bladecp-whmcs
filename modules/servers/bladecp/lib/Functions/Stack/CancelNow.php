<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Botão administrativo "Cancelar agora (sem destruir)".
 * `POST /customer/provisions/:uuid/cancel { when: 'now' }`: suspende
 * preservando os dados (reversível); NÃO destrói infra. Distinto do
 * `Suspend` de inadimplência — registra a intenção/motivo de cancelar.
 * Ver `CancelService`.
 */
final class CancelNow
{
    public static function run(array $params): string
    {
        return CancelService::cancel($params, 'now');
    }
}
