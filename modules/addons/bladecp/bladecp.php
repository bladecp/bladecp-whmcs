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
use WHMCS\Module\Addon\Bladecp\About;
use WHMCS\Module\Addon\Bladecp\Admin\I18n;
use WHMCS\Module\Addon\Bladecp\CatalogStateRepository;
use WHMCS\Module\Addon\Bladecp\Combos;
use WHMCS\Module\Addon\Bladecp\OptionGroupsMap;
use WHMCS\Module\Addon\Bladecp\WebhookEventStore;
use WHMCS\Module\Addon\Bladecp\VibeFreeClientArea;
use WHMCS\Module\Addon\Bladecp\VibeServiceIdentityClientArea;
use WHMCS\Module\Addon\Bladecp\CommercialActionClientArea;
use WHMCS\Module\Addon\Bladecp\CommercialUpgradeClientArea;
use WHMCS\Module\Addon\Bladecp\VibeContextReauthorizeClientArea;

/** Sem campos de credencial. A chave da API permanece no Server record. */
function bladecp_config(): array
{
    return [
        'name' => 'BladeCP',
        'description' => I18n::system('addon.config.description'),
        'version' => (string) About::metadata()['version'],
        'author' => 'BladeCP',
        'language' => 'english',
        'fields' => [],
    ];
}

function bladecp_activate(): array
{
    try {
        bladecp_install_schemas();
        Combos::healAccess();
        return [
            'status' => 'success',
            'description' => I18n::system('addon.activate.success'),
        ];
    } catch (\Throwable) {
        return [
            'status' => 'error',
            'description' => I18n::system('addon.activate.failed'),
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
        'description' => I18n::system('addon.deactivate.success'),
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
        echo '<div class="alert alert-danger">'
            . htmlspecialchars(I18n::t('addon.output.failed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
}

/**
 * Adds the client area copy, in the customer's language, to a route result.
 *
 * Every client route of the addon goes through this, including its error
 * fallback, so a template never prints a fixed language.
 *
 * @param array<string,mixed> $page
 * @return array<string,mixed>
 */
function bladecp_client_copy_page(array $page): array
{
    try {
        $vars = is_array($page['vars'] ?? null) ? $page['vars'] : [];
        $vars['bladecpCopy'] = \WHMCS\Module\Addon\Bladecp\ClientCopy::domain('client-flows');
        $page['vars'] = $vars;
    } catch (\Throwable) {
    }

    return $page;
}

/**
 * Client route used by the Vibe Free call to action.
 *
 * GET only renders an authenticated, SSL-only transition. The mutation is a
 * CSRF-protected POST handled by VibeFreeClientArea.
 */
function bladecp_vibe_free_clientarea(array $vars): array
{
    try {
        return bladecp_client_copy_page(VibeFreeClientArea::render($vars));
    } catch (\Throwable) {
        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP Vibe',
            'templatefile' => 'vibe-free',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'activationError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'vibe_access_unavailable'),
                'redirectTo' => '',
                'stripIdentityCallbackUrl' => false,
                'traceId' => '',
                'autoSubmit' => false,
                'partnerBrandLogoHtml' => \WHMCS\Module\Server\Bladecp\Ui\Brand::logo(32),
            ],
        ]);
    }
}

function bladecp_federation_clientarea(array $vars): array
{
    try { return bladecp_client_copy_page(\WHMCS\Module\Addon\Bladecp\FederationClientArea::render($vars)); }
    catch (\Throwable) {
        try { if (function_exists('logActivity')) { logActivity('BladeCP Vibe Owner SSO: route=unexpected_exception'); } } catch (\Throwable) { }
        return bladecp_client_copy_page(['pagetitle' => 'BladeCP Vibe', 'templatefile' => 'vibe-federation', 'requirelogin' => true, 'forcessl' => true,
            'vars' => ['transitionError' => true, 'transitionUrl' => '', 'autoSubmit' => false]]);
    }
}

/** Authenticated activation route for an existing paid Vibe Pro service. */
function bladecp_vibe_service_identity_clientarea(array $vars): array
{
    try {
        return bladecp_client_copy_page(VibeServiceIdentityClientArea::render($vars));
    } catch (\Throwable) {
        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP Vibe',
            'templatefile' => 'vibe-service-identity',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'activationError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'vibe_access_unavailable'),
                'identityError' => '',
                'identityRedirect' => '',
                'redirectTo' => '',
                'serviceId' => 0,
                'stripIdentityCallbackUrl' => false,
                'partnerBrandLogoHtml' => \WHMCS\Module\Server\Bladecp\Ui\Brand::logo(32),
            ],
        ]);
    }
}

/** Fixed, authenticated destination for opaque commercial Vibe tickets. */
function bladecp_commercial_handoff_clientarea(array $vars): array
{
    try {
        return bladecp_client_copy_page(CommercialActionClientArea::render($vars));
    } catch (\Throwable) {
        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP Vibe',
            'templatefile' => 'commercial-handoff',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'redirectTo' => '',
                'commercialActionError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'commercial_action_failed'),
                'partnerBrandLogoHtml' => \WHMCS\Module\Server\Bladecp\Ui\Brand::logo(32),
            ],
        ]);
    }
}

function bladecp_commercial_upgrade_clientarea(array $vars): array
{
    try {
        return bladecp_client_copy_page(CommercialUpgradeClientArea::render($vars));
    } catch (\Throwable) {
        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP Vibe', 'templatefile' => 'commercial-upgrade',
            'requirelogin' => true, 'forcessl' => true,
            'vars' => ['commercialUpgradeError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'offer_unopened')],
        ]);
    }
}

/** Fixed, authenticated return point for stale direct Vibe contexts. */
function bladecp_vibe_context_reauthorize_clientarea(array $vars): array
{
    try {
        return bladecp_client_copy_page(VibeContextReauthorizeClientArea::render($vars));
    } catch (\Throwable) {
        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP Vibe',
            'templatefile' => 'vibe-context-reauthorize',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'redirectTo' => '',
                'contextRecoveryError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'access_unconfirmed'),
                'partnerBrandLogoHtml' => \WHMCS\Module\Server\Bladecp\Ui\Brand::logo(32),
            ],
        ]);
    }
}

/** Account confirmation for a migration started by the account holder in cPanel. */
function bladecp_migration_claim_clientarea(array $vars): array
{
    try {
        return \WHMCS\Module\Addon\Bladecp\MigrationClaimClientArea::render($vars);
    } catch (\Throwable) {
        return [
            'pagetitle' => 'BladeCP',
            'templatefile' => 'migration-claim',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => ['claimHtml' => '<div class="bcp-claim"><div class="alert alert-danger" role="alert">'
                . htmlspecialchars((string) (\WHMCS\Module\Addon\Bladecp\MigrationClaimClientArea::copy()['failed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</div></div>'],
        ];
    }
}

/**
 * WHMCS resolves module callbacks case-insensitively. The addon and server
 * module therefore share this single global callback without redeclaring it.
 */
if (!function_exists('bladecp_ClientArea')) {
    function bladecp_ClientArea(array $params)
    {
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'wordpress'
            && class_exists(\WHMCS\Module\Addon\Bladecp\WordPress\ClientArea::class)) {
            return \WHMCS\Module\Addon\Bladecp\WordPress\ClientArea::render();
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'fast-deploy'
            && class_exists(\WHMCS\Module\Addon\Bladecp\FastDeployClientArea::class)) {
            return \WHMCS\Module\Addon\Bladecp\FastDeployClientArea::render();
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (in_array((string) ($_GET['action'] ?? ''), ['vibe-federation', 'vibe-admin-support'], true) || \WHMCS\Module\Addon\Bladecp\FederationFlow::handlesCallback($_GET))) {
            return bladecp_federation_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'vibe-service-identity') {
            return bladecp_vibe_service_identity_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'vibe-identity-callback'
            && is_array($_SESSION['bladecp_vibe_service_identity_context'] ?? null)
            && (($_SESSION['bladecp_vibe_service_identity_context']['route'] ?? '') === 'vibe-service-identity')) {
            return bladecp_vibe_service_identity_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && in_array((string) ($_GET['action'] ?? ''), ['vibe-free', 'vibe-identity-callback'], true)) {
            return bladecp_vibe_free_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'commercial-handoff') {
            return bladecp_commercial_handoff_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'commercial-upgrade') {
            return bladecp_commercial_upgrade_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'vibe-context-reauthorize') {
            return bladecp_vibe_context_reauthorize_clientarea($params);
        }
        if ((string) ($_GET['m'] ?? '') === 'bladecp'
            && (string) ($_GET['action'] ?? '') === 'claim') {
            return bladecp_migration_claim_clientarea($params);
        }

        if (function_exists('bladecp_dispatch')) {
            return bladecp_dispatch('ClientArea', $params);
        }

        return bladecp_client_copy_page([
            'pagetitle' => 'BladeCP',
            'templatefile' => 'vibe-free',
            'requirelogin' => true,
            'forcessl' => true,
            'vars' => [
                'activationError' => \WHMCS\Module\Addon\Bladecp\ClientCopy::text('client-flows', 'vibe_access_unavailable'),
                'redirectTo' => '',
                'traceId' => '',
                'autoSubmit' => false,
            ],
        ]);
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
    \WHMCS\Module\Addon\Bladecp\FastDeploy\BillingStore::installSchema();
}
