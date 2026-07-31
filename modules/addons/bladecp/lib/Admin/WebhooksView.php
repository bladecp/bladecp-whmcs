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
            'Conectar webhooks',
            'Registra este WHMCS para receber eventos de provisão da '
                . 'BladeCP (suspensão / reativação / cancelamento) e '
                . 'refletir o status do serviço automaticamente.'
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
                    . Layout::icon('check') . '<div>Reenvio solicitado. Uma nova '
                    . 'entrega foi criada com o mesmo identificador do evento.'
                    . '</div></div>'
                : '<div class="bcp-flash bcp-flash-no"><span class="bcp-pf-x">'
                    . '!</span><div>' . Layout::escape((string) ($_GET['replay_error']
                        ?? 'Não foi possível reenviar a entrega.'))
                    . '</div></div>';
        }
        if ($success !== '') {
            $flash .= '<div class="bcp-flash bcp-flash-ok">' . Layout::icon('check')
                . '<div><strong>' . Layout::escape($success) . '</strong> O segredo '
                . 'foi protegido e permanece disponível somente para o '
                . 'receiver deste servidor.</div></div>';
        }
        if ($legacyPlaintext) {
            $flash .= '<div class="bcp-flash bcp-flash-warn"><div><strong>'
                . 'Migração de segurança necessária.</strong> Foi detectada '
                . 'uma configuração antiga em texto simples. Ela não está '
                . 'ativa. Reconecte ou rotacione os webhooks para substituí-la '
                . 'por um segredo protegido.</div></div>';
        }
        if ($source === 'reconnect') {
            $flash .= '<div class="bcp-flash bcp-flash-warn"><div><strong>'
                . 'Reconexão necessária.</strong> Uma operação anterior não '
                . 'pôde confirmar o estado remoto com segurança. O receiver '
                . 'permanece inativo até uma nova conexão.</div></div>';
        }

        $srcLabel = [
            'stored' => 'gravado neste WHMCS',
            'constant' => 'constante configuration.php',
            'env' => 'variável de ambiente',
            'legacy' => 'migração necessária',
            'reconnect' => 'reconexão necessária',
        ][$source] ?? '';
        $status = '<div class="bcp-sec">Situação</div>'
            . self::whRow('Servidor BladeCP', $serverOk
                ? '<span class="bcp-pill on">conectado</span>'
                : '<span class="bcp-pill off">não configurado</span>')
            . self::whRow('Segredo de assinatura', in_array(
                $source,
                ['legacy', 'reconnect'],
                true
            )
                ? '<span class="bcp-pill off">inativo, '
                    . Layout::escape($srcLabel) . '</span>'
                : ($hasSecret
                ? '<span class="bcp-pill on">configurado ('
                    . Layout::escape($srcLabel) . ')</span>'
                : '<span class="bcp-pill off">ausente</span>'))
            . ($endpointId !== ''
                ? self::whRow(
                    'Endpoint registrado',
                    '<span class="bcp-csub" style="text-align:left">'
                    . Layout::escape($endpointId) . '</span>'
                )
                : '');

        $evChips = '';
        foreach (WebhookConfig::EVENTS as $ev) {
            $evChips .= '<span class="bcp-pill">' . Layout::escape($ev) . '</span> ';
        }
        $events = '<div class="bcp-sec">Eventos assinados</div>'
            . '<p class="bcp-row-v" style="text-align:left">' . $evChips
            . '</p>';

        $form = '<form method="post" action="' . Layout::escape($base) . '">'
            . Layout::token()
            . '<input type="hidden" name="action" value="webhook-connect">'
            . '<div class="bcp-fg"><label class="bcp-fg-l" for="bcp-webhook-url">'
            . 'URL pública do receiver</label><div class="bcp-fg-c">'
            . '<input id="bcp-webhook-url" type="url" name="url" value="' . Layout::escape($url) . '" '
            . 'placeholder="https://seu-whmcs/modules/addons/bladecp/webhook.php" '
            . 'class="bcp-setup-input" style="max-width:520px"></div>'
            . '<small class="bcp-fg-d">Detectada do System URL do WHMCS. '
            . 'Ajuste se o WHMCS estiver atrás de proxy/domínio diferente. '
            . 'Precisa ser acessível pela BladeCP via HTTPS. Para testes '
            . 'locais, use um túnel HTTPS e informe aqui a URL pública '
            . 'terminada em /modules/addons/bladecp/webhook.php.</small></div>'
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit"' . ($serverOk ? '' : ' disabled') . '>'
            . Layout::icon('webhook')
            . ($hasSecret ? 'Reconectar webhooks' : 'Conectar webhooks')
            . '</button>'
            . ($endpointId !== '' ? AdminPage::postBtn(
                $base,
                'webhook-rotate',
                [],
                'Rotacionar segredo',
                'Rotacionar o segredo do webhook? A operação só será concluída '
                    . 'quando o novo valor estiver protegido neste WHMCS.',
                'refresh'
            ) : '')
            . '</div></form>';

        if (!$serverOk) {
            $form = '<p class="bcp-row-v">Conecte sua API key primeiro na '
                . 'aba <a href="' . Layout::escape($base) . '&action=setup">'
                . 'Configuração rápida</a> — o registro do webhook usa a sua '
                . 'credencial BladeCP.</p>' . $form;
        }

        $note = '<div class="bcp-chips-note">"Conectar" registra este '
            . 'endpoint na BladeCP com a sua API key e guarda o segredo de '
            . 'assinatura protegido aqui mesmo. O <code>webhook.php</code> '
            . 'o lê automaticamente, sem cópia manual e sem exibição na '
            . 'interface. Use "Rotacionar" para substituir o valor. Nada é logado.'
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
        $sec = '<div class="bcp-sec">Diagnóstico de entregas</div>';
        if ($endpointUuid === '') {
            return $sec . '<p class="bcp-row-v muted">Conecte o endpoint para '
                . 'consultar entregas e reenviar falhas.</p>';
        }
        $options = '<option value="">Todos os estados</option>';
        foreach (WebhookDiagnostics::STATUSES as $status) {
            $options .= '<option value="' . Layout::escape($status) . '"'
                . ($selectedStatus === $status ? ' selected' : '') . '>'
                . Layout::escape($status) . '</option>';
        }
        $filter = '<form method="get" action="' . Layout::escape($base)
            . '" class="bcp-actions">'
            . '<input type="hidden" name="module" value="bladecp">'
            . '<input type="hidden" name="action" value="webhooks">'
            . '<label class="sr-only" for="bcp-delivery-status">Filtrar entregas por estado</label>'
            . '<select id="bcp-delivery-status" name="delivery_status" class="form-control" '
            . 'style="max-width:220px">' . $options . '</select>'
            . '<button class="btn btn-default" type="submit">Filtrar</button>'
            . '</form>';
        if ($data === null) {
            return $sec . $filter . '<p class="bcp-row-v muted">Não foi '
                . 'possível carregar o diagnóstico. Confirme os escopos '
                . '<code>webhooks:read</code> da API key.</p>';
        }
        if ($data['items'] === []) {
            return $sec . $filter . '<p class="bcp-row-v muted">Nenhuma '
                . 'entrega neste filtro.</p>';
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
                        'Reenviar esta entrega? Uma nova tentativa será criada.',
                        JSON_THROW_ON_ERROR
                    ))
                    . ')">' . Layout::token()
                    . '<input type="hidden" name="action" value="webhook-replay">'
                    . '<input type="hidden" name="delivery_uuid" value="'
                    . Layout::escape($deliveryUuid) . '">'
                    . '<button class="btn btn-default" type="submit">'
                    . Layout::icon('refresh') . 'Reenviar</button></form>';
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
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Evento</th>'
            . '<th scope="col">Status</th><th scope="col">Tentativa</th><th scope="col">Resposta</th>'
            . '<th scope="col">Atualizado em</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>'
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
                . 'Página ' . $page . ' de ' . $pages . ' · ' . $total
                . ' entrega(s)</span>'
                . ($page > 1 ? '<a class="btn btn-default" href="'
                    . Layout::escape($url($page - 1)) . '">Anterior</a>' : '')
                . ($page < $pages ? '<a class="btn btn-default" href="'
                    . Layout::escape($url($page + 1)) . '">Próxima</a>' : '')
                . '</div>'
            : '';
        return $sec . $filter . $table . $pager
            . '<p class="bcp-chips-note">A lista não contém payload nem '
            . 'segredo. O replay cria uma nova delivery, preservando o '
            . 'identificador do evento para deduplicação.</p>';
    }

    public static function webhookStatusBadge(string $status): string
    {
        return match ($status) {
            'succeeded' => '<span class="bcp-badge is-ok">succeeded</span>',
            'failed', 'dead' => '<span class="bcp-badge is-err">'
                . Layout::escape($status) . '</span>',
            'pending', 'delivering' => '<span class="bcp-badge is-warn">'
                . Layout::escape($status) . '</span>',
            default => '<span class="bcp-pill">' . Layout::escape($status) . '</span>',
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
