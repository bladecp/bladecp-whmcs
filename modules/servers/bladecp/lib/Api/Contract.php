<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Api;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Identificador de compatibilidade da API consumida pelo módulo. */
final class Contract
{
    public const CUSTOMER_WHMCS_V1 = 'customer-whmcs.v1';
}
