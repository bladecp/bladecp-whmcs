<?php

/**
 * BladeCP, módulo adicional do WHMCS para configuração e acompanhamento.
 *
 * Este addon fornece a superfície administrativa usada para catálogo,
 * opções, webhooks e diagnóstico. Ele não armazena credenciais: a fonte
 * única continua sendo o Server record do WHMCS. O provisionador e o addon
 * são distribuídos juntos no mesmo pacote.
 *
 * O bootstrap PSR-4 nativo registra as classes do addon e reutiliza as
 * classes compartilhadas do módulo de servidor, sem exigir Composer.
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../servers/bladecp/bootstrap.php';

\WHMCS\Module\Server\Bladecp\Bootstrap::registerAddonAutoloaders(
    __DIR__,
    __DIR__ . '/../../servers/bladecp'
);

use WHMCS\Module\Addon\Bladecp\AdminPage;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;
use WHMCS\Module\Addon\Bladecp\CatalogStateRepository;
use WHMCS\Module\Addon\Bladecp\Combos;
use WHMCS\Module\Addon\Bladecp\OptionGroupsMap;
use WHMCS\Module\Addon\Bladecp\WebhookEventStore;

/** Sem campos de credencial. A chave da API permanece no Server record. */
function bladecp_config(): array
{
    return [
        'name' => 'BladeCP',
        'description' => 'Combos/bundles de componentes + sync de '
            . 'Configurable Options no order form. Não armazena '
            . 'credenciais — a API key fica no Server record '
            . '(Setup → Products/Services → Servers).',
        'version' => '0.9.0-rc.4',
        'author' => 'BladeCP',
        'language' => 'english',
        'fields' => [],
    ];
}

function bladecp_activate(): array
{
    try {
        bladecp_install_schemas();
        return [
            'status' => 'success',
            'description' => 'Tabelas de combos e opções configuráveis '
                . 'criadas. Abra o addon para gerenciar bundles e '
                . 'sincronizar as opções configuráveis.',
        ];
    } catch (\Throwable) {
        return [
            'status' => 'error',
            'description' => 'Não foi possível preparar as tabelas do módulo.',
        ];
    }
}

/**
 * Aplica migrações idempotentes em instalações existentes.
 *
 * @param array<string,mixed> $vars
 */
function bladecp_upgrade(array $vars): void
{
    try {
        bladecp_install_schemas();
    } catch (\Throwable $e) {
        try {
            \WHMCS\Module\Server\Bladecp\Helpers\Logger::logCall(
                'addon_upgrade_error',
                ['version' => (string) ($vars['version'] ?? '')],
                ['error' => $e->getMessage()]
            );
        } catch (\Throwable) {
        }
    }
}

function bladecp_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'O addon foi desativado. Catálogo, vínculos, '
            . 'combos e histórico foram preservados para uma reativação segura.',
    ];
}

/** Página admin do addon. Fail-soft: erro → aviso branded, nunca 500. */
function bladecp_output(array $vars): void
{
    try {
        I18n::boot(is_array($vars['_lang'] ?? null) ? $vars['_lang'] : []);
        echo AdminPage::render($vars);
    } catch (\Throwable $e) {
        try {
            \WHMCS\Module\Server\Bladecp\Helpers\Logger::logCall(
                'addon_output_error',
                [],
                ['error' => $e->getMessage()]
            );
        } catch (\Throwable) {
        }
        echo '<div class="alert alert-danger">Não foi possível carregar '
            . 'esta tela do BladeCP. Tente novamente e consulte o '
            . 'diagnóstico do módulo.</div>';
    }
}

/** Instala ou atualiza somente estruturas persistentes e idempotentes. */
function bladecp_install_schemas(): void
{
    Combos::installSchema();
    OptionGroupsMap::installSchema();
    CatalogStateRepository::installSchema();
    // Ledger persistente: processing, processed, failed e ignored.
    WebhookEventStore::installSchema();
}
