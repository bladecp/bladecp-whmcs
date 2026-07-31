<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\AdminPage;
use WHMCS\Module\Addon\Bladecp\WebhookConfig;
use WHMCS\Module\Addon\Bladecp\WebhookDiagnostics;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Renderização e histórico da aba Webhooks. */
final class WebhooksView
{
    public static function render(string $base, string $error = "", string $success = ""): string
    {
        return self::webhooksView($base, $error, $success);
    }
    public static function webhooksView(
        string $base,
        string $error = '',
        string $success = ''
    ): string {
        $h = Layout::header(
            I18n::t('webhooks.header.title'),
            I18n::t('webhooks.header.description')
        );

        $serverOk = ServerConfig::params() !== null;
        $url = WebhookConfig::publicUrl();
        $source = WebhookConfig::secretSource();
        $endpointId = WebhookConfig::getEndpointId();
        $hasSecret = !in_array(
            $source,
            ['none', 'legacy', 'reconnect'],
            true
        );
        $legacyPlaintext = WebhookConfig::hasLegacyPlaintextSecret();
        $deliveryStatus = trim((string) ($_GET['delivery_status'] ?? ''));
        $deliveryPage = isset($_GET['delivery_page'])
            ? max(1, (int) $_GET['delivery_page']) : 1;
        $deliveries = $endpointId !== ''
            ? WebhookDiagnostics::deliveries(
                $endpointId,
                $deliveryStatus,
                $deliveryPage
            )
            : null;

        $flash = '';
        if ($error !== '') {
            $flash .= '<div class="bcp-flash bcp-flash-no">'
                . '<span class="bcp-pf-x">!</span><div>' . Layout::escape($error)
                . '</div></div>';
        }
        if (isset($_GET['replayed'])) {
            $flash .= !empty($_GET['replayed'])
                ? '<div class="bcp-flash bcp-flash-ok">'
                    . Layout::icon('check') . '<div>'
                    . Layout::escape(I18n::t('webhooks.flash.replayRequested'))
                    . '</div></div>'
                : '<div class="bcp-flash bcp-flash-no"><span class="bcp-pf-x">'
                    . '!</span><div>' . Layout::escape((string) ($_GET['replay_error']
                        ?? I18n::t('webhooks.flash.replayFailed')))
                    . '</div></div>';
        }
        if ($success !== '') {
            $flash .= '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div><strong>' . Layout::escape($success) . '</strong> '
                . Layout::escape(I18n::t('webhooks.flash.successDetail')) . '</div></div>';
        }
        if ($legacyPlaintext) {
            $flash .= '<div class="bcp-flash bcp-flash-warn"><div><strong>'
                . Layout::escape(I18n::t('webhooks.flash.legacyTitle')) . '</strong> '
                . Layout::escape(I18n::t('webhooks.flash.legacyDetail')) . '</div></div>';
        }
        if ($source === 'reconnect') {
            $flash .= '<div class="bcp-flash bcp-flash-warn"><div><strong>'
                . Layout::escape(I18n::t('webhooks.flash.reconnectTitle')) . '</strong> '
                . Layout::escape(I18n::t('webhooks.flash.reconnectDetail')) . '</div></div>';
        }

        $srcLabel = [
            'stored' => I18n::t('webhooks.source.stored'),
            'constant' => I18n::t('webhooks.source.constant'),
            'env' => I18n::t('webhooks.source.env'),
            'legacy' => I18n::t('webhooks.source.legacy'),
            'reconnect' => I18n::t('webhooks.source.reconnect'),
        ][$source] ?? '';
        $status = '<div class="bcp-sec">' . Layout::escape(I18n::t('webhooks.status.title')) . '</div>'
            . self::whRow(I18n::t('webhooks.status.server'), $serverOk
                ? '<span class="bcp-pill on">' . Layout::escape(I18n::t('webhooks.status.connected')) . '</span>'
                : '<span class="bcp-pill off">' . Layout::escape(I18n::t('webhooks.status.notConfigured')) . '</span>')
            . self::whRow(I18n::t('webhooks.status.secret'), in_array(
                $source,
                ['legacy', 'reconnect'],
                true
            )
                ? '<span class="bcp-pill off">' . Layout::escape(I18n::t('webhooks.status.inactive')) . ', '
                    . Layout::escape($srcLabel) . '</span>'
                : ($hasSecret
                ? '<span class="bcp-pill on">' . Layout::escape(I18n::t('webhooks.status.configured')) . ' ('
                    . Layout::escape($srcLabel) . ')</span>'
                : '<span class="bcp-pill off">' . Layout::escape(I18n::t('webhooks.status.missing')) . '</span>'))
            . ($endpointId !== ''
                ? self::whRow(
                    I18n::t('webhooks.status.endpoint'),
                    '<span class="bcp-csub" style="text-align:left">'
                    . Layout::escape($endpointId) . '</span>'
                )
                : '');

        $evChips = '';
        foreach (WebhookConfig::EVENTS as $ev) {
            $evChips .= '<span class="bcp-pill">' . Layout::escape($ev) . '</span> ';
        }
        $events = '<div class="bcp-sec">' . Layout::escape(I18n::t('webhooks.events.title')) . '</div>'
            . '<p class="bcp-row-v" style="text-align:left">' . $evChips
            . '</p>';

        $form = '<form method="post" action="' . Layout::escape($base) . '">'
            . Layout::token()
            . '<input type="hidden" name="action" value="webhook-connect">'
            . '<div class="bcp-fg"><label class="bcp-fg-l" for="bcp-webhook-url">'
            . Layout::escape(I18n::t('webhooks.form.urlLabel')) . '</label><div class="bcp-fg-c">'
            . '<input id="bcp-webhook-url" type="url" name="url" value="' . Layout::escape($url) . '" '
            . 'placeholder="https://seu-whmcs/modules/addons/bladecp/webhook.php" '
            . 'class="bcp-setup-input" style="max-width:520px"></div>'
            . '<small class="bcp-fg-d">' . Layout::escape(I18n::t('webhooks.form.urlHint')) . '</small></div>'
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit"' . ($serverOk ? '' : ' disabled') . '>'
            . Layout::icon('webhook')
            . Layout::escape(I18n::t($hasSecret ? 'webhooks.actions.reconnect' : 'webhooks.actions.connect'))
            . '</button>'
            . ($endpointId !== '' ? AdminPage::postBtn(
                $base,
                'webhook-rotate',
                [],
                I18n::t('webhooks.actions.rotate'),
                I18n::t('webhooks.actions.rotateConfirm'),
                'refresh'
            ) : '')
            . '</div></form>';

        if (!$serverOk) {
            $form = '<p class="bcp-row-v">' . Layout::escape(I18n::t('webhooks.form.serverRequiredPrefix')) . ' '
                . '<a href="' . Layout::escape($base) . '&action=setup">'
                . Layout::escape(I18n::t('webhooks.form.setupLink')) . '</a>. '
                . Layout::escape(I18n::t('webhooks.form.serverRequiredSuffix')) . '</p>' . $form;
        }

        $note = '<div class="bcp-chips-note">' . Layout::escape(I18n::t('webhooks.note.before'))
            . ' <code>webhook.php</code> ' . Layout::escape(I18n::t('webhooks.note.after'))
            . '</div>';
        $diagnostics = self::webhookDeliveriesBlock(
            $base,
            $endpointId,
            $deliveries,
            $deliveryStatus
        );

        return Layout::card($h, Layout::tabs($base, 'webhooks')
            . $flash . $status . $events . $form . $note . $diagnostics);
    }

    /** Linha rótulo→valor da seção "Situação" dos webhooks. */
    public static function whRow(string $k, string $v): string
    {
        return '<div class="bcp-statrow"><span class="bcp-statk">'
            . Layout::escape($k) . '</span>' . $v . '</div>';
    }

    /**
     * Lista redigida de deliveries. A API e WebhookDiagnostics removem payload
     * e segredo; a UI escapa todos os campos e só oferece replay com CSRF.
     */
    public static function webhookDeliveriesBlock(
        string $base,
        string $endpointUuid,
        ?array $data,
        string $selectedStatus
    ): string {
        $sec = '<div class="bcp-sec">' . Layout::escape(I18n::t('webhooks.deliveries.title')) . '</div>';
        if ($endpointUuid === '') {
            return $sec . '<p class="bcp-row-v muted">'
                . Layout::escape(I18n::t('webhooks.deliveries.endpointRequired')) . '</p>';
        }
        $options = '<option value="">' . Layout::escape(I18n::t('webhooks.deliveries.allStatuses')) . '</option>';
        foreach (WebhookDiagnostics::STATUSES as $status) {
            $options .= '<option value="' . Layout::escape($status) . '"'
                . ($selectedStatus === $status ? ' selected' : '') . '>'
                . Layout::escape($status) . '</option>';
        }
        $filter = '<form method="get" action="' . Layout::escape($base)
            . '" class="bcp-actions">'
            . '<input type="hidden" name="module" value="bladecp">'
            . '<input type="hidden" name="action" value="webhooks">'
            . '<label class="sr-only" for="bcp-delivery-status">'
            . Layout::escape(I18n::t('webhooks.deliveries.filterLabel')) . '</label>'
            . '<select id="bcp-delivery-status" name="delivery_status" class="form-control" '
            . 'style="max-width:220px">' . $options . '</select>'
            . '<button class="btn btn-default" type="submit">'
            . Layout::escape(I18n::t('webhooks.actions.filter')) . '</button>'
            . '</form>';
        if ($data === null) {
            return $sec . $filter . '<p class="bcp-row-v muted">'
                . Layout::escape(I18n::t('webhooks.deliveries.unavailableBefore'))
                . ' <code>webhooks:read</code> '
                . Layout::escape(I18n::t('webhooks.deliveries.unavailableAfter')) . '</p>';
        }
        if ($data['items'] === []) {
            return $sec . $filter . '<p class="bcp-row-v muted">'
                . Layout::escape(I18n::t('webhooks.deliveries.empty')) . '</p>';
        }

        $rows = '';
        foreach ($data['items'] as $delivery) {
            $deliveryUuid = (string) ($delivery['uuid'] ?? '');
            $status = (string) ($delivery['status'] ?? '');
            $action = '';
            if (in_array($status, ['failed', 'dead'], true)) {
                $action = '<form method="post" action="' . Layout::escape($base)
                    . '" style="display:inline" onsubmit="return confirm('
                    . Layout::escape(json_encode(
                        I18n::t('webhooks.actions.replayConfirm'),
                        JSON_THROW_ON_ERROR
                    ))
                    . ')">' . Layout::token()
                    . '<input type="hidden" name="action" value="webhook-replay">'
                    . '<input type="hidden" name="delivery_uuid" value="'
                    . Layout::escape($deliveryUuid) . '">'
                    . '<button class="btn btn-default" type="submit">'
                    . Layout::icon('refresh') . Layout::escape(I18n::t('webhooks.actions.replay')) . '</button></form>';
            }
            $error = trim((string) ($delivery['lastError'] ?? ''));
            $responseCode = $delivery['responseCode'] ?? null;
            $rows .= '<tr><td><strong>'
                . Layout::escape((string) ($delivery['eventType'] ?? '')) . '</strong>'
                . '<div class="bcp-csub">'
                . Layout::escape((string) ($delivery['eventId'] ?? '')) . '</div></td>'
                . '<td>' . self::webhookStatusBadge($status) . '</td>'
                . '<td>' . (int) ($delivery['attempt'] ?? 0) . '</td>'
                . '<td>' . ($responseCode !== null ? (int) $responseCode : '-')
                . ($error !== '' ? '<div class="bcp-csub">'
                    . Layout::escape($error) . '</div>' : '') . '</td>'
                . '<td class="bcp-row-v">'
                . Layout::escape((string) ($delivery['updatedAt'] ?? '')) . '</td>'
                . '<td>' . $action . '</td></tr>';
        }
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">'
            . Layout::escape(I18n::t('webhooks.table.event')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('webhooks.table.status')) . '</th><th scope="col">'
            . Layout::escape(I18n::t('webhooks.table.attempt')) . '</th><th scope="col">'
            . Layout::escape(I18n::t('webhooks.table.response')) . '</th>'
            . '<th scope="col">' . Layout::escape(I18n::t('webhooks.table.updatedAt')) . '</th><th scope="col"><span class="sr-only">'
            . Layout::escape(I18n::t('webhooks.table.actions')) . '</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        $page = max(1, (int) ($data['page'] ?? 1));
        $limit = max(1, (int) ($data['perPage'] ?? WebhookDiagnostics::PAGE_SIZE));
        $total = max(0, (int) ($data['total'] ?? 0));
        $pages = max(1, (int) ceil($total / $limit));
        $url = static function (int $target) use ($base, $selectedStatus): string {
            return $base . '&action=webhooks&delivery_page=' . $target
                . ($selectedStatus !== ''
                    ? '&delivery_status=' . rawurlencode($selectedStatus) : '');
        };
        $pager = $pages > 1
            ? '<div class="bcp-actions bcp-mkt-pager"><span class="bcp-row-v">'
                . Layout::escape(I18n::t('webhooks.pager.summary', ['page' => $page, 'pages' => $pages, 'total' => $total]))
                . '</span>'
                . ($page > 1 ? '<a class="btn btn-default" href="'
                    . Layout::escape($url($page - 1)) . '">' . Layout::escape(I18n::t('webhooks.pager.previous')) . '</a>' : '')
                . ($page < $pages ? '<a class="btn btn-default" href="'
                    . Layout::escape($url($page + 1)) . '">' . Layout::escape(I18n::t('webhooks.pager.next')) . '</a>' : '')
                . '</div>'
            : '';
        return $sec . $filter . $table . $pager
            . '<p class="bcp-chips-note">'
            . Layout::escape(I18n::t('webhooks.deliveries.note')) . '</p>';
    }

    public static function webhookStatusBadge(string $status): string
    {
        return match ($status) {
            'succeeded' => '<span class="bcp-badge is-ok">' . Layout::escape(I18n::t('webhooks.deliveryStatus.succeeded')) . '</span>',
            'failed', 'dead' => '<span class="bcp-badge is-err">'
                . Layout::escape(self::statusLabel($status)) . '</span>',
            'pending', 'delivering' => '<span class="bcp-badge is-warn">'
                . Layout::escape(self::statusLabel($status)) . '</span>',
            default => '<span class="bcp-pill">' . Layout::escape(self::statusLabel($status)) . '</span>',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'succeeded', 'failed', 'dead', 'pending', 'delivering' => I18n::t('webhooks.deliveryStatus.' . $status),
            default => $status,
        };
    }

    // ── POST (PRG) ──

    /**
     * Redirect após POST (PRG). A URL vai num literal de string JS via
     * json_encode (NÃO htmlspecialchars): `&` precisa ficar `&` dentro
     * de `window.location=...`, senão vira `&amp;` e os params quebram
     * (`amp;action=...` → renderiza a lista em vez do destino). O
     * fallback `<noscript>` usa o href escapado normalmente (contexto
     * de atributo HTML, onde `&amp;` é o correto).
     */
    /**
     * Wizard "Configuração rápida": preflight + colar a chave +
     * detectar ambiente + validar (GET /me) + gravar o Server record.
     * Lê flags não-sensíveis do redirect (PRG); a chave NUNCA é
     * refletida no HTML.
     */
}
