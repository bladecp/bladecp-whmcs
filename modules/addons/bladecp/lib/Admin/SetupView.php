<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\Provisioning;
use WHMCS\Module\Addon\Bladecp\Setup;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Fluxo de configuração inicial e produto de teste. */
final class SetupView
{
    public static function render(string $base, string $error = '', bool $forceEdit = false, string $federationMessage = ''): string
    {
        $cards = self::setupProductSection($base) . FederationView::render($base, $federationMessage);

        return self::setupView($base, $error, $forceEdit)
            . ($cards !== '' ? '<div class="bcp-setup-extras">' . $cards . '</div>' : '');
    }
    public static function setupView(
        string $base,
        string $error = '',
        bool $forceEdit = false
    ): string
    {
        // Preflight (ambiente).
        $pf = '';
        foreach (Setup::preflight() as $c) {
            $pf .= '<li class="bcp-pf-' . ($c['ok'] ? 'ok' : 'no') . '">'
                . ($c['ok'] ? Layout::icon('check') : '<span class="bcp-pf-x">!</span>')
                . '<span>' . Layout::escape(self::preflightLabel((string) $c['label'])) . '</span>'
                . '<em>' . Layout::escape(self::preflightDetail((string) $c['detail'])) . '</em></li>';
        }

        $existing = Setup::existingServer();
        $serverContext = AdminServerContext::resolve();
        $editMode = $existing === null
            || $forceEdit
            || (string) ($_GET['edit'] ?? '') === '1';
        $serverDisabled = $existing !== null && (int) ($existing->disabled ?? 0) === 1;
        $connectionHealth = $existing === null || $editMode || $serverDisabled
            ? ['state' => 'unconfigured', 'ok' => false, 'who' => null, 'error' => null]
            : Setup::currentConnectionHealth();
        $connectionState = $serverDisabled
            ? 'disabled'
            : (string) ($connectionHealth['state'] ?? 'unavailable');

        // Mensagem de sucesso após o redirecionamento: revalida via Server record já
        // gravado pra mostrar "conectado como…".
        $flash = '';
        $okMode = (string) ($_GET['ok'] ?? '');
        if (in_array($okMode, ['created', 'updated', 'tested'], true)) {
            $who = null;
            $p = $okMode !== 'tested' ? ServerConfig::params() : null;
            if (is_array($p)) {
                $v = Setup::validate(
                    (string) ($p['serveraccesshash'] ?? ''),
                    (string) ($p['serverhostname'] ?? '')
                );
                $who = $v['ok'] ? $v['who'] : null;
            }
            $line = $okMode === 'tested'
                ? Layout::escape(I18n::t('setup.flash.connectionValidatedBefore'))
                    . ' <strong>' . Layout::escape(I18n::t('setup.flash.connectionValidatedStrong'))
                    . '</strong>' . Layout::escape(I18n::t('setup.period'))
                : Layout::escape(I18n::t('setup.flash.serverBefore')) . ' <strong>'
                    . Layout::escape(I18n::t($okMode === 'updated'
                        ? 'setup.flash.updated'
                        : 'setup.flash.created'))
                    . '</strong>' . Layout::escape(I18n::t('setup.period'));
            $line .= $who !== null
                ? ' ' . Layout::escape(I18n::t('setup.flash.connectedAs'))
                    . ' <strong>' . Layout::escape($who) . '</strong>'
                    . Layout::escape(I18n::t('setup.period'))
                : '';
            $next = $okMode === 'tested'
                ? Layout::escape(I18n::t('setup.flash.testedNext'))
                : Layout::escape(I18n::t('setup.flash.createdNextBefore'))
                    . ' <strong>Stack Container</strong> '
                    . Layout::escape(I18n::t('setup.flash.createdNextAfter'));
            $flash = '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div>' . $line . '<div class="bcp-flash-sub">'
                . $next . '</div></div></div>';
        }
        // Mensagem temporária do produto de teste.
        $prodMode = (string) ($_GET['product'] ?? '');
        if ($prodMode === 'created' || $prodMode === 'exists') {
            $ppid = (int) ($_GET['pid'] ?? 0);
            $verb = I18n::t($prodMode === 'exists'
                ? 'setup.productFlash.exists'
                : 'setup.productFlash.created');
            $links = $ppid > 0
                ? ' <a href="' . Layout::escape('configproducts.php?action=edit&id=' . $ppid)
                    . '">' . Layout::escape(I18n::t('setup.links.editProduct')) . '</a> · <a href="' . Layout::escape($base)
                    . '&action=orderform&pid=' . $ppid . '">'
                    . Layout::escape(I18n::t('setup.links.syncOrderForm')) . '</a>'
                : '';
            $flash .= '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div>' . Layout::escape(I18n::t('setup.productFlash.before')) . ' <strong>'
                . Layout::escape($verb) . '</strong> '
                . Layout::escape(I18n::t('setup.productFlash.after')) . $links
                . '<div class="bcp-flash-sub">'
                . Layout::escape(I18n::t('setup.productFlash.nextBefore'))
                . ' <strong>BladeCP Plan</strong> '
                . Layout::escape(I18n::t('setup.productFlash.nextAfter'))
                . '</div></div></div>';
        }
        if ($error !== '') {
            $flash .= '<div class="bcp-flash bcp-flash-no">'
                . '<span class="bcp-pf-x">!</span><div>' . Layout::escape($error)
                . '</div></div>';
        }

        // Form (chave mascarada; sem autocomplete; nunca pré-preenchida).
        $formTitle = I18n::t($existing === null ? 'setup.form.connect' : 'setup.form.replaceCredential');
        $buttonLabel = $existing === null
            ? I18n::t('setup.form.connectAndConfigure')
            : I18n::t('setup.form.validateAndReplace');
        $current = '';
        if ($existing !== null) {
            $host = trim((string) ($existing->hostname ?? ''));
            $current = '<p class="bcp-setup-note">'
                . Layout::escape(I18n::t('setup.form.currentCredentialBefore'))
                . ($host !== '' ? ' <code>' . Layout::escape($host) . '</code>' : '')
                . ' ' . Layout::escape(I18n::t('setup.form.currentCredentialAfter')) . '</p>';
        }
        $form = '<form method="post" action="' . Layout::escape($base)
            . '" class="bcp-setup-form">' . Layout::token()
            . '<input type="hidden" name="action" value="setup-save">'
            . '<label class="bcp-setup-lbl" for="bcp-apikey">'
            . Layout::escape(I18n::t('setup.form.apiKey')) . '</label>'
            . '<input type="password" id="bcp-apikey" name="apikey" required '
            . 'autocomplete="off" spellcheck="false" '
            . 'placeholder="bcp_sand_… · bcp_live_…" '
            . 'class="bcp-setup-input">'
            . '<p class="bcp-setup-hint">'
            . Layout::escape(I18n::t('setup.form.environmentBefore'))
            . ' <strong>Sandbox</strong> (<code>…_sand_…</code>) '
            . Layout::escape(I18n::t('setup.form.environmentOr'))
            . ' <strong>' . Layout::escape(I18n::t('setup.environment.production'))
            . '</strong> (<code>…_live_…</code>) '
            . Layout::escape(I18n::t('setup.form.environmentAfter')) . '</p>'
            . $current
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit">' . Layout::icon('plug')
            . Layout::escape($buttonLabel) . '</button>'
            . ($existing !== null
                ? '<a class="btn btn-default" href="' . Layout::escape(
                    $base . '&action=setup'
                ) . '">' . Layout::escape(I18n::t('setup.actions.cancel')) . '</a>'
                : '')
            . '</div></form>';

        $connection = $form;
        if ($existing !== null && !$editMode) {
            $host = trim((string) ($existing->hostname ?? ''));
            $environment = $host === Setup::HOST_SANDBOX
                ? I18n::t('setup.environment.sandbox')
                : ($host === Setup::HOST_PRODUCTION
                    ? I18n::t('setup.environment.production')
                    : I18n::t('setup.environment.custom'));
            $healthState = $connectionState;
            $healthClass = match ($healthState) {
                'valid' => 'is-valid',
                'rejected' => 'is-invalid',
                default => 'is-unavailable',
            };
            $eyebrowKey = match ($healthState) {
                'valid' => 'setup.current.validEyebrow',
                'rejected' => 'setup.current.rejectedEyebrow',
                'disabled' => 'setup.current.disabledEyebrow',
                default => 'setup.current.unavailableEyebrow',
            };
            $titleKey = match ($healthState) {
                'valid' => 'setup.current.validTitle',
                'rejected' => 'setup.current.rejectedTitle',
                'disabled' => 'setup.current.disabledTitle',
                default => 'setup.current.unavailableTitle',
            };
            $credentialDetailKey = match ($healthState) {
                'valid' => 'setup.current.validCredentialDetail',
                'rejected' => 'setup.current.rejectedCredentialDetail',
                'disabled' => 'setup.current.disabledCredentialDetail',
                default => 'setup.current.unavailableCredentialDetail',
            };
            $connection = '<div class="bcp-setup-current'
                . ' ' . $healthClass . '">'
                . '<div class="bcp-setup-current-head"><span class="bcp-setup-current-icon">'
                . ($healthState === 'valid' ? Layout::icon('check') : '!') . '</span><div>'
                . '<span class="bcp-eyebrow">'
                . Layout::escape(I18n::t($eyebrowKey)) . '</span><h4>'
                . Layout::escape(I18n::t($titleKey))
                . '</h4></div><span class="bcp-badge '
                . ($healthState === 'valid' ? 'is-ok' : 'is-warn') . '">'
                . Layout::escape($environment) . '</span></div>'
                . '<dl><div><dt>' . Layout::escape(I18n::t('setup.current.server'))
                . '</dt><dd><code>'
                . Layout::escape($host !== '' ? $host : Setup::HOST_PRODUCTION)
                . '</code></dd></div><div><dt>'
                . Layout::escape(I18n::t('setup.current.credential')) . '</dt>'
                . '<dd>' . Layout::escape(I18n::t($credentialDetailKey)) . '</dd>'
                . '</div></dl><div class="bcp-actions">'
                . '<form method="post" action="' . Layout::escape($base) . '">'
                . Layout::token()
                . '<input type="hidden" name="action" value="setup-test">'
                . '<button class="btn btn-primary" type="submit">'
                . Layout::icon('refresh')
                . Layout::escape(I18n::t('setup.actions.testConnection')) . '</button></form>'
                . '<a class="btn btn-default" href="' . Layout::escape(
                    $base . '&action=setup&edit=1'
                ) . '">' . Layout::icon('pencil')
                . Layout::escape(I18n::t('setup.actions.replaceKey')) . '</a>'
                . '</div></div>';
        }

        $body = $flash . self::adminServerContextView($base, $serverContext)
            . '<div class="bcp-setup-grid">'
            . '<section><h4 class="bcp-setup-h">'
            . Layout::escape(I18n::t('setup.environment.title')) . '</h4>'
            . '<ul class="bcp-pf">' . $pf . '</ul></section>'
            . '<section class="bcp-setup-connect"><h4 class="bcp-setup-h">'
            . Layout::escape($existing !== null && !$editMode
                ? I18n::t('setup.header.connectionTitle')
                : $formTitle)
            . '</h4>' . $connection
            . '</section></div>';
        $body .= '<p class="bcp-docs-help">'
            . Layout::escape(I18n::t('setup.docs.before')) . ' '
            . Layout::docsLink(I18n::t('setup.docs.link')) . '</p>';

        return Layout::card(
            Layout::header(
                $existing === null
                    ? I18n::t('setup.header.quickTitle')
                    : ($editMode
                        ? I18n::t('setup.header.replaceTitle')
                        : I18n::t('setup.header.connectionTitle')),
                $existing === null
                    ? I18n::t('setup.header.quickSubtitle')
                    : ($editMode
                        ? I18n::t('setup.header.replaceSubtitle')
                        : I18n::t(match ($connectionState) {
                            'valid' => 'setup.header.validSubtitle',
                            'rejected' => 'setup.header.rejectedSubtitle',
                            'disabled' => 'setup.header.disabledSubtitle',
                            default => 'setup.header.unavailableSubtitle',
                        }))
            ),
            Layout::tabs($base, 'setup') . $body
        );
    }

    /**
     * Contexto seguro do servidor BladeCP selecionado. A seleção persiste
     * somente o ID do Server record e nunca mostra ou replica a credencial.
     *
     * @param array{state:string,serverId:?int,automatic:bool,server:?array,candidates:array} $context
     */
    public static function adminServerContextView(string $base, array $context): string
    {
        $state = (string) ($context['state'] ?? 'missing');
        $server = is_array($context['server'] ?? null) ? $context['server'] : null;
        $candidates = is_array($context['candidates'] ?? null) ? $context['candidates'] : [];

        if ($state === 'configured' && $server !== null) {
            $name = trim((string) ($server['name'] ?? I18n::t('setup.server.defaultName')));
            $host = trim((string) ($server['hostname'] ?? ''));
            return '<div class="bcp-flash bcp-flash-ok bcp-server-context"><div><strong>'
                . Layout::escape(I18n::t('setup.server.selected')) . '</strong> '
                . Layout::escape($name !== '' ? $name : I18n::t('setup.server.defaultName'))
                . ($host !== '' ? ' <code>' . Layout::escape($host) . '</code>' : '')
                . (!empty($context['automatic'])
                    ? '<div class="bcp-flash-sub">'
                        . Layout::escape(I18n::t('setup.server.autoDetected')) . '</div>'
                    : '')
                . '</div></div>';
        }

        $title = $state === 'ambiguous'
            ? I18n::t('setup.server.ambiguousTitle')
            : ($state === 'invalid'
                ? I18n::t('setup.server.invalidTitle')
                : I18n::t('setup.server.missingTitle'));
        $detail = $state === 'ambiguous'
            ? I18n::t('setup.server.ambiguousDetail')
            : ($state === 'invalid'
                ? I18n::t('setup.server.invalidDetail')
                : I18n::t('setup.server.missingDetail'));
        $options = '<option value="">'
            . Layout::escape(I18n::t('setup.server.selectPlaceholder')) . '</option>';
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = (int) ($candidate['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($candidate['name'] ?? I18n::t('setup.server.defaultName')));
            $host = trim((string) ($candidate['hostname'] ?? ''));
            $options .= '<option value="' . $id . '">' . Layout::escape(
                ($name !== '' ? $name : I18n::t('setup.server.defaultName'))
                . ($host !== '' ? ' (' . $host . ')' : '')
                . ' [' . I18n::t('setup.server.numberShort', ['id' => (string) $id]) . ']'
            ) . '</option>';
        }
        $selection = $candidates === []
            ? '<input type="hidden" name="server_id" value="0"><button class="btn btn-default" type="submit">'
                . Layout::escape(I18n::t('setup.server.clearInvalid')) . '</button>'
            : '<label for="bcp-admin-server-id" class="sr-only">'
                . Layout::escape(I18n::t('setup.server.defaultName'))
                . '</label><div class="bcp-fg-c"><select id="bcp-admin-server-id" name="server_id" required>'
                . $options . '</select></div><button class="btn btn-primary" type="submit">'
                . Layout::escape(I18n::t('setup.server.useSelected')) . '</button>';

        return '<section class="bcp-flash bcp-flash-warn bcp-server-context"><div><strong>'
            . Layout::escape($title) . '</strong><div class="bcp-flash-sub">' . Layout::escape($detail)
            . '</div></div><form method="post" action="' . Layout::escape($base)
            . '" class="bcp-actions">' . Layout::token()
            . '<input type="hidden" name="action" value="admin-server-select">'
            . $selection . '</form></section>';
    }

    /**
     * Cria um produto de teste (Stack, grupo oculto, gratuito)
     * em um clique. Só aparece depois que o servidor está conectado.
     */
    public static function setupProductSection(string $base): string
    {
        if (ServerConfig::params() === null) {
            return '';
        }

        $pid = Provisioning::findStarterProduct();
        if ($pid !== null) {
            $body = '<p class="bcp-setup-note">'
                . Layout::escape(I18n::t('setup.product.existsBefore')) . ' '
                . '<a href="' . Layout::escape('configproducts.php?action=edit&id=' . $pid)
                . '">' . Layout::escape(I18n::t('setup.links.edit')) . '</a> · <a href="' . Layout::escape($base)
                . '&action=orderform&pid=' . $pid . '">'
                . Layout::escape(I18n::t('setup.links.syncOrderForm')) . '</a>'
                . Layout::escape(I18n::t('setup.period')) . '</p>';
        } else {
            $body = '<p class="bcp-setup-hint">'
                . Layout::escape(I18n::t('setup.product.createBefore'))
                . ' <strong>Stack Container</strong> '
                . Layout::escape(I18n::t('setup.product.createAfter')) . '</p>'
                . '<form method="post" action="' . Layout::escape($base) . '">' . Layout::token()
                . '<input type="hidden" name="action" value="product-create">'
                . '<div class="bcp-actions"><button class="btn btn-default" type="submit">'
                . Layout::icon('package')
                . Layout::escape(I18n::t('setup.product.createAction')) . '</button></div></form>';
        }

        return Layout::card(
            Layout::header(
                I18n::t('setup.product.title'),
                I18n::t('setup.product.subtitle'),
                false
            ),
            $body
        );
    }

    /** Resumo do servidor usado somente na composição da Visão geral. */

    private static function preflightLabel(string $label): string
    {
        return match ($label) {
            'PHP ≥ 8.1' => I18n::t('setup.preflight.php'),
            'Extensão cURL' => I18n::t('setup.preflight.curl'),
            'Extensão JSON' => I18n::t('setup.preflight.json'),
            'SSL (OpenSSL)' => I18n::t('setup.preflight.ssl'),
            default => $label,
        };
    }

    private static function preflightDetail(string $detail): string
    {
        return match ($detail) {
            'disponível' => I18n::t('setup.preflight.available'),
            'ausente' => I18n::t('setup.preflight.unavailable'),
            default => $detail,
        };
    }
}
