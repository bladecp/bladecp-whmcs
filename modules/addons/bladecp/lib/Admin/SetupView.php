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
    public static function render(string $base, string $error = '', bool $forceEdit = false): string
    {
        return self::setupView($base, $error, $forceEdit);
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
                . '<span>' . Layout::escape($c['label']) . '</span>'
                . '<em>' . Layout::escape($c['detail']) . '</em></li>';
        }

        $existing = Setup::existingServer();
        $serverContext = AdminServerContext::resolve();
        $editMode = $existing === null
            || $forceEdit
            || (string) ($_GET['edit'] ?? '') === '1';

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
                ? 'Conexão com a BladeCP <strong>validada com sucesso</strong>.'
                : 'Servidor BladeCP <strong>'
                    . ($okMode === 'updated' ? 'atualizado' : 'criado')
                    . '</strong>.';
            $line .= $who !== null
                ? ' Conectado como <strong>' . Layout::escape($who) . '</strong>.'
                : '';
            $next = $okMode === 'tested'
                ? 'A credencial armazenada e o servidor responderam corretamente.'
                : 'Próximo passo: crie um produto BladeCP '
                    . '(Setup → Products/Services), tipo '
                    . '<strong>Stack Container</strong>, em um grupo oculto '
                    . 'para os testes.';
            $flash = '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div>' . $line . '<div class="bcp-flash-sub">'
                . $next . '</div></div></div>';
        }
        // Mensagem temporária do produto de teste.
        $prodMode = (string) ($_GET['product'] ?? '');
        if ($prodMode === 'created' || $prodMode === 'exists') {
            $ppid = (int) ($_GET['pid'] ?? 0);
            $verb = $prodMode === 'exists' ? 'já existe' : 'criado';
            $links = $ppid > 0
                ? ' <a href="' . Layout::escape('configproducts.php?action=edit&id=' . $ppid)
                    . '">Editar produto</a> · <a href="' . Layout::escape($base)
                    . '&action=orderform&pid=' . $ppid . '">Sincronizar order form</a>'
                : '';
            $flash .= '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div>Produto de teste <strong>' . $verb . '</strong> em grupo '
                . 'oculto.' . $links . '<div class="bcp-flash-sub">Finalize escolhendo '
                . 'o <strong>BladeCP Plan</strong> na aba Module Settings do produto. '
                . 'Ele nasce gratuito (R$0) — a precificação fica a seu critério.'
                . '</div></div></div>';
        }
        if ($error !== '') {
            $flash .= '<div class="bcp-flash bcp-flash-no">'
                . '<span class="bcp-pf-x">!</span><div>' . Layout::escape($error)
                . '</div></div>';
        }

        // Form (chave mascarada; sem autocomplete; nunca pré-preenchida).
        $formTitle = $existing === null ? 'Conectar' : 'Substituir credencial';
        $buttonLabel = $existing === null
            ? 'Conectar e configurar servidor'
            : 'Validar e substituir chave';
        $current = '';
        if ($existing !== null) {
            $host = trim((string) ($existing->hostname ?? ''));
            $current = '<p class="bcp-setup-note">A nova chave substituirá a '
                . 'credencial atual do servidor'
                . ($host !== '' ? ' <code>' . Layout::escape($host) . '</code>' : '')
                . ' somente depois de ser validada com sucesso.</p>';
        }
        $form = '<form method="post" action="' . Layout::escape($base)
            . '" class="bcp-setup-form">' . Layout::token()
            . '<input type="hidden" name="action" value="setup-save">'
            . '<label class="bcp-setup-lbl" for="bcp-apikey">API key BladeCP</label>'
            . '<input type="password" id="bcp-apikey" name="apikey" required '
            . 'autocomplete="off" spellcheck="false" '
            . 'placeholder="bcp_sand_… · bcp_live_…" '
            . 'class="bcp-setup-input">'
            . '<p class="bcp-setup-hint">O ambiente — <strong>Sandbox</strong> '
            . '(<code>…_sand_…</code>) ou <strong>Produção</strong> '
            . '(<code>…_live_…</code>) — e o endereço do servidor são '
            . 'detectados pela chave. Você não precisa digitar o Hostname.</p>'
            . $current
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit">' . Layout::icon('plug')
            . Layout::escape($buttonLabel) . '</button>'
            . ($existing !== null
                ? '<a class="btn btn-default" href="' . Layout::escape(
                    $base . '&action=setup'
                ) . '">Cancelar</a>'
                : '')
            . '</div></form>';

        $connection = $form;
        if ($existing !== null && !$editMode) {
            $host = trim((string) ($existing->hostname ?? ''));
            $environment = $host === Setup::HOST_SANDBOX
                ? 'Sandbox'
                : ($host === Setup::HOST_PRODUCTION ? 'Produção' : 'Personalizado');
            $disabled = (int) ($existing->disabled ?? 0) === 1;
            $connection = '<div class="bcp-setup-current'
                . ($disabled ? ' is-attention' : '') . '">'
                . '<div class="bcp-setup-current-head"><span class="bcp-setup-current-icon">'
                . ($disabled ? '!' : Layout::icon('check')) . '</span><div>'
                . '<span class="bcp-eyebrow">CONEXÃO CONFIGURADA</span><h4>'
                . ($disabled ? 'Servidor desativado no WHMCS' : 'Servidor BladeCP configurado')
                . '</h4></div><span class="bcp-badge '
                . ($disabled ? 'is-warn' : 'is-ok') . '">'
                . Layout::escape($environment) . '</span></div>'
                . '<dl><div><dt>Servidor</dt><dd><code>'
                . Layout::escape($host !== '' ? $host : Setup::HOST_PRODUCTION)
                . '</code></dd></div><div><dt>Credencial</dt>'
                . '<dd>Protegida no registro do servidor, sem exibição nesta tela.</dd>'
                . '</div></dl><div class="bcp-actions">'
                . '<form method="post" action="' . Layout::escape($base) . '">'
                . Layout::token()
                . '<input type="hidden" name="action" value="setup-test">'
                . '<button class="btn btn-primary" type="submit">'
                . Layout::icon('refresh') . 'Testar conexão</button></form>'
                . '<a class="btn btn-default" href="' . Layout::escape(
                    $base . '&action=setup&edit=1'
                ) . '">' . Layout::icon('pencil') . 'Substituir chave</a>'
                . '</div></div>';
        }

        $body = $flash . self::adminServerContextView($base, $serverContext)
            . '<div class="bcp-setup-grid">'
            . '<section><h4 class="bcp-setup-h">Ambiente</h4>'
            . '<ul class="bcp-pf">' . $pf . '</ul></section>'
            . '<section class="bcp-setup-connect"><h4 class="bcp-setup-h">'
            . Layout::escape($existing !== null && !$editMode ? 'Conexão' : $formTitle)
            . '</h4>' . $connection
            . '</section></div>';
        $body .= '<p class="bcp-docs-help">Quer conferir o processo antes de continuar? '
            . Layout::docsLink('Abrir documentação BladeCP') . '</p>';

        return Layout::card(
            Layout::header(
                $existing === null
                    ? 'Configuração rápida'
                    : ($editMode
                        ? 'Substituir credencial BladeCP'
                        : 'Conexão BladeCP'),
                $existing === null
                    ? 'Conecte seu WHMCS à BladeCP em um passo: cole a chave e pronto.'
                    : ($editMode
                        ? 'Substitua a credencial somente quando precisar trocar o acesso.'
                        : 'Sua conexão já está configurada. Teste ou altere o acesso somente quando necessário.')
            ),
            Layout::tabs($base, 'setup') . $body
        ) . self::setupProductSection($base);
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
            $name = trim((string) ($server['name'] ?? 'Servidor BladeCP'));
            $host = trim((string) ($server['hostname'] ?? ''));
            $id = (int) ($server['id'] ?? 0);
            return '<div class="bcp-flash bcp-flash-ok bcp-server-context"><div><strong>Servidor BladeCP selecionado:</strong> '
                . Layout::escape($name !== '' ? $name : 'Servidor BladeCP')
                . ($host !== '' ? ' <code>' . Layout::escape($host) . '</code>' : '')
                . ($id > 0 ? ' (servidor #' . $id . ')' : '')
                . (!empty($context['automatic']) ? '<div class="bcp-flash-sub">Único servidor elegível detectado automaticamente.</div>' : '')
                . '</div></div>';
        }

        $title = $state === 'ambiguous'
            ? 'Escolha o servidor BladeCP usado por estas telas.'
            : ($state === 'invalid'
                ? 'A seleção anterior do servidor não é mais válida.'
                : 'Nenhum servidor BladeCP elegível foi encontrado.');
        $detail = $state === 'ambiguous'
            ? 'Há mais de um servidor elegível. Selecione um antes de consultar catálogo, preços, marketplace ou webhooks.'
            : ($state === 'invalid'
                ? 'Escolha um servidor válido ou remova a seleção inválida antes de continuar.'
                : 'Conecte um servidor pela configuração abaixo para iniciar.');
        $options = '<option value="">Selecione um servidor</option>';
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = (int) ($candidate['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($candidate['name'] ?? 'Servidor BladeCP'));
            $host = trim((string) ($candidate['hostname'] ?? ''));
            $options .= '<option value="' . $id . '">' . Layout::escape(
                ($name !== '' ? $name : 'Servidor BladeCP')
                . ($host !== '' ? ' (' . $host . ')' : '')
                . ' [#' . $id . ']'
            ) . '</option>';
        }
        $selection = $candidates === []
            ? '<input type="hidden" name="server_id" value="0"><button class="btn btn-default" type="submit">Remover seleção inválida</button>'
            : '<label for="bcp-admin-server-id" class="sr-only">Servidor BladeCP</label><div class="bcp-fg-c"><select id="bcp-admin-server-id" name="server_id" required>'
                . $options . '</select></div><button class="btn btn-primary" type="submit">Usar este servidor</button>';

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
            $body = '<p class="bcp-setup-note">Já existe um produto de teste BladeCP. '
                . '<a href="' . Layout::escape('configproducts.php?action=edit&id=' . $pid)
                . '">Editar</a> · <a href="' . Layout::escape($base)
                . '&action=orderform&pid=' . $pid . '">Sincronizar order form</a>.</p>';
        } else {
            $body = '<p class="bcp-setup-hint">Cria um produto <strong>Stack '
                . 'Container</strong> em um grupo <strong>oculto</strong>, gratuito '
                . '(R$0), já ligado ao módulo e ao seu servidor — pronto pra um pedido '
                . 'de teste sem afetar seu catálogo nem cobrar nada. Você finaliza '
                . 'escolhendo o Plan na aba Module Settings.</p>'
                . '<form method="post" action="' . Layout::escape($base) . '">' . Layout::token()
                . '<input type="hidden" name="action" value="product-create">'
                . '<div class="bcp-actions"><button class="btn btn-default" type="submit">'
                . Layout::icon('package') . 'Criar produto de teste</button></div></form>';
        }

        return '<div class="bcp-setup-product-card">' . Layout::card(
            Layout::header(
                'Produto de teste',
                'Opcional — um Stack pronto pra testar.',
                false
            ),
            $body
        ) . '</div>';
    }

    /** Resumo do servidor usado somente na composição da Visão geral. */
}
