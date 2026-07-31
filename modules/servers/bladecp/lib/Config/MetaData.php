<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\Bladecp\Config;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

final class MetaData
{
    public static function build(): array
    {
        return [
            'DisplayName' => 'BladeCP',
            'APIVersion' => '1.1',
            'RequiresServer' => true,
            'DefaultNonSSLPort' => '80',
            'DefaultSSLPort' => '443',
            'ServiceSingleSignOnLabel' => 'Abrir painel BladeCP',
            // Server Sync (_ListAccounts, WHMCS 7.8+): casa cada conta
            // retornada pelo campo `domain` contra o domínio do serviço, pra
            // reconciliar serviços WHMCS ↔ provisões BladeCP.
            'ListAccountsUniqueIdentifierField' => 'domain',
            'ListAccountsUniqueIdentifierDisplayName' => 'Domain',
        ];
    }
}
