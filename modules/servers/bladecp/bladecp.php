<?php

/**
 * BladeCP — WHMCS Provisioning Module (entrypoint).
 *
 * Thin global functions delegating to ProductTypeRouter. The
 * `BladeCP Product Type` ConfigOption selects Functions\{Stack,Edge,Dedicated\}.
 * Vibe is recognized for compatibility, but its services are not provisioned
 * by this module.
 *
 * Native WHMCS autoload (no Composer, no vendor/) over `lib/`.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require __DIR__ . '/moduleVersion.php';
require_once __DIR__ . '/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerServerAutoloader(__DIR__);

use WHMCS\Module\Server\Bladecp\Config\ConfigOptions;
use WHMCS\Module\Server\Bladecp\Config\MetaData;
use WHMCS\Module\Server\Bladecp\Helpers\Logger;
use WHMCS\Module\Server\Bladecp\Router\ProductTypeRouter;

/**
 * Every global is the same 3 lines: delegate to the router, catch
 * Throwable, log + return the WHMCS-contract error string. WHMCS calls
 * these by reflection so they stay untyped at the boundary.
 */
function bladecp_dispatch(string $function, array $params)
{
    try {
        return ProductTypeRouter::dispatch($function, $params);
    } catch (\Throwable $e) {
        Logger::logCall($function, $params, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return $e->getMessage();
    }
}

function bladecp_MetaData(): array
{
    return MetaData::build();
}

function bladecp_ConfigOptions(array $params = []): array
{
    return ConfigOptions::build($params);
}

/**
 * Aba branded "Configuração & Status" no admin do serviço — UI
 * própria do módulo (sem addon, renderização nativa do WHMCS).
 * Fail-soft: qualquer erro → aba some, nunca quebra a tela admin.
 */
function bladecp_AdminServicesTabFields(array $params): array
{
    try {
        return [
            'BladeCP' =>
                \WHMCS\Module\Server\Bladecp\Ui\AdminPanel::render($params),
        ];
    } catch (\Throwable) {
        return [];
    }
}

function bladecp_TestConnection(array $params): array
{
    return bladecp_dispatch('TestConnection', $params);
}

/**
 * `_ListAccounts` (Shared) — Server Sync (WHMCS 7.8+). Lista as provisões do
 * partner (GET /provisions) no contrato `{success, accounts:[{domain,...}]}`
 * pra o WHMCS reconciliar serviço ↔ provisão (casa por `domain`, ver MetaData).
 */
function bladecp_ListAccounts(array $params)
{
    return bladecp_dispatch('ListAccounts', $params);
}

/**
 * `_UsageUpdate` (Shared). O WHMCS chama uma vez por servidor
 * (cron diário); atualiza `tblhosting.{disk,bw}usage/limit` de todos os
 * serviços BladeCP do servidor a partir do uso vivo do backend, pra
 * alimentar a cobrança de overage. Fail-soft (não derruba o cron).
 */
function bladecp_UsageUpdate(array $params)
{
    return bladecp_dispatch('UsageUpdate', $params);
}

function bladecp_CreateAccount(array $params)
{
    return bladecp_dispatch('CreateAccount', $params);
}

function bladecp_SuspendAccount(array $params)
{
    return bladecp_dispatch('SuspendAccount', $params);
}

function bladecp_UnsuspendAccount(array $params)
{
    return bladecp_dispatch('UnsuspendAccount', $params);
}

function bladecp_TerminateAccount(array $params)
{
    return bladecp_dispatch('TerminateAccount', $params);
}

function bladecp_ChangePackage(array $params)
{
    return bladecp_dispatch('ChangePackage', $params);
}

/**
 * Botões administrativos para cancelamento dirigido por cobrança. O WHMCS
 * os despacha pelo
 * framework admin (com o token CSRF dele) chamando `bladecp_<suffix>`.
 * `Terminate` (destrutivo) segue sendo o botão nativo de destruição.
 */
function bladecp_AdminCustomButtonArray(array $params = []): array
{
    return [
        'Cancelar no fim do ciclo' => 'CancelAtCycleEnd',
        'Cancelar agora (sem destruir)' => 'CancelNow',
    ];
}

function bladecp_CancelAtCycleEnd(array $params)
{
    return bladecp_dispatch('CancelAtCycleEnd', $params);
}

function bladecp_CancelNow(array $params)
{
    return bladecp_dispatch('CancelNow', $params);
}

function bladecp_ChangePassword(array $params)
{
    return bladecp_dispatch('ChangePassword', $params);
}

function bladecp_ServiceSingleSignOn(array $params): array
{
    return bladecp_dispatch('ServiceSingleSignOn', $params);
}

function bladecp_ClientArea(array $params)
{
    return bladecp_dispatch('ClientArea', $params);
}
