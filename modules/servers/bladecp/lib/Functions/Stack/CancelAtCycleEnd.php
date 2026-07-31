<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Functions\Stack;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Botão administrativo "Cancelar no fim do ciclo".
 * `POST /customer/provisions/:uuid/cancel { when: 'cycle_end' }`: o
 * serviço roda até o fim do ciclo (não destrói). Ver `CancelService`.
 */
final class CancelAtCycleEnd
{
    public static function run(array $params): string
    {
        return CancelService::cancel($params, 'cycle_end');
    }
}
