<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp;

use WHMCS\Module\Server\Bladecp\Api\Catalog;
use WHMCS\Module\Server\Bladecp\Api\OptionCatalog;
use WHMCS\Module\Server\Bladecp\Helpers\ComponentRef;
use WHMCS\Module\Server\Bladecp\Helpers\AdminServerContext;
use WHMCS\Module\Server\Bladecp\Helpers\SelectionRules;
use WHMCS\Module\Server\Bladecp\Helpers\ServerConfig;
use WHMCS\Module\Server\Bladecp\Ui\Assets;
use WHMCS\Module\Server\Bladecp\Ui\Brand;
use WHMCS\Module\Server\Bladecp\Ui\Icons;
use WHMCS\Module\Addon\Bladecp\Admin\Layout;
use WHMCS\Module\Addon\Bladecp\Admin\DiagnosticsView;
use WHMCS\Module\Addon\Bladecp\Admin\MarketplaceView;
use WHMCS\Module\Addon\Bladecp\Admin\WebhooksView;
use WHMCS\Module\Addon\Bladecp\Admin\SetupView;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * UI admin do addon de combos — mesmo design system `.bcp-*` do
 * server module (ponte read-only via autoloader do bladecp.php, sem
 * duplicar CSS/logo). Roteia por `action`. Quando aberto a partir do
 * card de um produto (`?pid=`), mostra o contexto do produto +
 * atribuição do combo (a "ancoragem no module settings"). Tudo
 * escapado; POST com token WHMCS quando disponível; fail-soft.
 */
final class AdminPage
{
    public static function render(array $vars): string
    {
        $base = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=bladecp');
        $action = (string) ($_REQUEST['action'] ?? '');
        // $_REQUEST (não só $_GET): no POST de orderform-apply o pid
        // chega como hidden field, não na query string. Sem isto o
        // sync "+ ligar a este produto" rodava com pid=0 → linkProduct
        // nunca era chamado → grupo órfão (não aparecia no order form).
        $pid = isset($_REQUEST['pid']) ? (int) $_REQUEST['pid'] : 0;

        // Mutações (fail-soft; sempre redireciona pra GET — PRG).
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            return self::handlePost($base, $action, $pid);
        }

        $body = match ($action) {
            'overview' => self::overviewView($base),
            'setup' => self::setupView($base),
            'planos' => self::plansView($base),
            'new', 'edit' => self::formView($base, $pid),
            'pricing' => self::pricingView($base, $pid),
            'prices' => self::pricesView($base),
            'orderform' => self::orderformView($base, $pid),
            'options' => self::optionsView($base, $pid),
            'marketplace' => self::marketplaceView($base),
            // "Minha conta" foi unificada na aba Marketplace; alias mantém
            // bookmarks antigos (?action=account) funcionando.
            'account' => self::marketplaceView($base),
            'webhooks' => self::webhooksView($base),
            'operation' => self::operationView($base),
            'diagnostics' => self::diagnosticsView($base),
            'combos' => self::listView($base, $pid),
            // Abrir o addon mostra o panorama e o roteiro de primeiro uso.
            // Aberto de um produto (?pid=) mantém os Combos daquele produto.
            default => $pid > 0 ? self::listView($base, $pid) : self::overviewView($base),
        };

        // Alertas de configuração continuam globais até existir um contexto
        // válido. O resumo do servidor configurado pertence à Visão geral.
        if ($action !== 'setup') {
            $body = self::setupBanner($base) . $body;
        }

        return self::shell($body);
    }

    // ── Views ──

    /**
     * Página inicial orientada à operação. Não toma decisões de catálogo:
     * apenas traduz sinais já disponíveis em próximos passos claros.
     */
    private static function overviewView(string $base): string
    {
        $server = ServerConfig::params();
        $plans = PlanSync::plans();
        $componentGroup = ConfigurableOptions::groupId(false);
        $webhookSource = WebhookConfig::secretSource();
        $readiness = self::addonSnapshot(
            'WHMCS\\Module\\Addon\\Bladecp\\IntegrationReadiness',
            ['snapshot', 'status', 'inspect', 'load']
        );
        $integrity = self::addonSnapshot(
            'WHMCS\\Module\\Addon\\Bladecp\\CatalogIntegrity',
            ['snapshot', 'summary', 'inspect', 'load']
        );

        $connected = $server !== null;
        $catalogReady = $plans !== [];
        $componentsReady = $componentGroup !== null;
        $webhookReady = $webhookSource !== 'none';
        $readyCount = ($connected ? 1 : 0) + ($catalogReady ? 1 : 0)
            + ($componentsReady ? 1 : 0) + ($webhookReady ? 1 : 0);

        $statusCards = self::overviewStatusCard(
            'Conexão',
            $connected ? 'Pronta' : 'Pendente',
            $connected
                ? 'A credencial do servidor está configurada.'
                : 'Conecte a conta para começar.',
            $base . '&action=setup',
            $connected ? 'Ver configuração' : 'Configurar conexão',
            $connected ? 'ok' : 'warn'
        );
        $statusCards .= self::overviewStatusCard(
            'Catálogo',
            $catalogReady ? 'Carregado' : 'Aguardando',
            $catalogReady
                ? count($plans) . ' plano(s) disponíveis para revisar.'
                : 'Importe depois de conectar a conta.',
            $base . '&action=planos',
            'Revisar planos',
            $catalogReady ? 'ok' : 'warn'
        );
        $statusCards .= self::overviewStatusCard(
            'Opções do pedido',
            $componentsReady ? 'Prontas' : 'Opcional',
            $componentsReady
                ? 'Os componentes podem ser vinculados aos produtos.'
                : 'Sincronize se quiser oferecer escolhas no carrinho.',
            $base . '&action=orderform',
            'Abrir componentes',
            $componentsReady ? 'ok' : 'muted'
        );
        $statusCards .= self::overviewStatusCard(
            'Atualizações',
            $webhookReady ? 'Ativas' : 'Opcional',
            $webhookReady
                ? 'Eventos de serviço podem atualizar o WHMCS.'
                : 'Conecte quando houver uma URL HTTPS pública.',
            $base . '&action=webhooks',
            'Ver webhooks',
            $webhookReady ? 'ok' : 'muted'
        );

        $steps = self::overviewStep(
            1,
            'Conectar a conta BladeCP',
            'Valide a credencial e guarde-a com segurança no servidor WHMCS.',
            $connected,
            $base . '&action=setup',
            $connected ? 'Revisar conexão' : 'Configurar conexão'
        );
        $steps .= self::overviewStep(
            2,
            'Revisar e importar os planos',
            'Veja quais produtos podem ser oferecidos e crie somente os que faltam.',
            $catalogReady,
            $base . '&action=planos',
            'Abrir planos'
        );
        $steps .= self::overviewStep(
            3,
            'Definir a experiência de compra',
            'Escolha entre componentes fixos ou opções para o cliente selecionar.',
            $componentsReady,
            $base . '&action=orderform',
            'Configurar componentes'
        );
        $steps .= self::overviewStep(
            4,
            'Testar antes de publicar',
            'Use um produto de teste e acompanhe a operação antes de abrir vendas.',
            false,
            $base . '&action=operation',
            'Abrir operação'
        );

        $integrityLine = self::snapshotLine($integrity, 'message');
        if ($integrityLine === '') {
            $integrityLine = $catalogReady
                ? 'O catálogo está disponível para revisão. Produtos já criados não são apagados por esta tela.'
                : 'Assim que a conexão estiver pronta, o módulo mostrará o estado do catálogo aqui.';
        }
        $readinessLine = self::snapshotLine($readiness, 'message');
        if ($readinessLine === '') {
            $readinessLine = $readyCount . ' de 4 áreas principais estão prontas para uso.';
        }

        $hero = '<section class="bcp-overview-hero" aria-labelledby="bcp-overview-title">'
            . '<div><span class="bcp-eyebrow">CENTRAL DE CONTROLE</span>'
            . '<h4 id="bcp-overview-title">Seu catálogo BladeCP, com contexto em cada etapa.</h4>'
            . '<p>Configure com calma, acompanhe o que está pronto e saiba exatamente para onde ir a seguir. Nenhuma ação desta tela publica ou altera produtos automaticamente.</p></div>'
            . '<div class="bcp-overview-score" aria-label="' . $readyCount . ' de 4 áreas prontas">'
            . '<strong>' . $readyCount . '<small>/4</small></strong><span>áreas prontas</span></div></section>';
        $serverContextCard = self::serverContextCard($base);
        if ($serverContextCard !== '') {
            $hero = '<div class="row bcp-overview-top">'
                . '<div class="col-md-8 col-lg-9">' . $hero . '</div>'
                . '<div class="col-md-4 col-lg-3">' . $serverContextCard . '</div></div>';
        }
        $quick = '<div class="bcp-overview-actions" aria-label="Ações rápidas">'
            . '<a class="btn btn-primary" href="' . self::e($connected ? $base . '&action=planos' : $base . '&action=setup') . '">'
            . self::ic($connected ? 'layers' : 'plug')
            . ($connected ? 'Revisar catálogo' : 'Começar configuração') . '</a>'
            . '<a class="btn btn-default" href="' . self::e($base . '&action=diagnostics') . '">'
            . self::ic('refresh') . 'Ver diagnóstico</a>'
            . '<a class="btn btn-default" href="' . self::e($base . '&action=operation') . '">'
            . self::ic('package') . 'Acompanhar operação</a>'
            . self::docsLink('Abrir documentação', 'btn btn-default') . '</div>';
        $context = '<div class="bcp-overview-context"><section><h5>Estado do catálogo</h5><p>'
            . self::e($integrityLine) . '</p><a href="' . self::e($base . '&action=planos')
            . '">Revisar planos</a></section><section><h5>Próximo passo</h5><p>'
            . self::e($readinessLine) . '</p><a href="' . self::e($base . '&action=diagnostics')
            . '">Entender os indicadores</a></section></div>';

        return self::card(
            self::header('BladeCP', 'Configuração, catálogo e acompanhamento do seu módulo em um só lugar.'),
            self::tabs($base, 'overview') . $hero . $quick
                . '<div class="bcp-overview-status" role="list">' . $statusCards . '</div>'
                . '<section class="bcp-onboarding" aria-labelledby="bcp-onboarding-title"><div class="bcp-section-heading">'
                . '<div><span class="bcp-eyebrow">PRIMEIRO USO</span><h4 id="bcp-onboarding-title">Roteiro recomendado</h4><p>Você pode voltar a qualquer etapa. O módulo preserva produtos existentes e mostra alertas antes de bloquear novas vendas.</p></div>'
                . '</div><ol class="bcp-onboarding-list">' . $steps . '</ol></section>' . $context
        );
    }

    private static function operationView(string $base): string
    {
        $h = self::header(
            'Operação',
            'Acompanhe o estado do catálogo e dos serviços sem alterar pedidos ou produtos por esta tela.'
        );
        $data = [];
        if (class_exists(Operation::class)) {
            try {
                $data = Operation::load($_GET);
            } catch (\Throwable) {
                $data = [];
            }
        }
        $body = '<section class="bcp-operation-intro"><span class="bcp-eyebrow">ACOMPANHAMENTO</span>'
            . '<h4>Visibilidade para a rotina, sem surpresas.</h4><p>Este painel é somente leitura. Use-o para identificar itens que merecem atenção antes de agir nas telas próprias do WHMCS.</p></section>';
        if ($data === []) {
            $body .= '<div class="bcp-empty-state"><strong>Ainda não há dados suficientes para resumir a operação.</strong>'
                . '<p>Conecte a conta, importe ou revise o catálogo e volte aqui. Enquanto isso, o diagnóstico mostra as verificações disponíveis.</p>'
                . '<a class="btn btn-default" href="' . self::e($base . '&action=diagnostics') . '">Ver diagnóstico</a></div>';
        } else {
            $capabilities = is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [];
            $summary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
            $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
            $services = is_array($data['services'] ?? null) ? $data['services'] : [];
            if (empty($capabilities['services'])) {
                $body .= '<div class="bcp-badge is-warn">A leitura dos serviços locais está indisponível nesta instalação. Os números abaixo não devem ser interpretados como zero confirmado.</div>';
            }
            $body .= self::operationSummary($summary)
                . self::operationFilters($base, $filters)
                . self::operationServices($base, $services, $filters)
                . '<div class="bcp-operation-evidence-grid">'
                . self::operationEvidence(
                    'Falhas recentes',
                    is_array($data['failures'] ?? null) ? $data['failures'] : [],
                    'Nenhuma falha do módulo foi encontrada nos registros disponíveis.'
                )
                . self::operationEvidence(
                    'Sincronizações recentes',
                    is_array($data['synchronizations'] ?? null) ? $data['synchronizations'] : [],
                    'Ainda não há sincronizações registradas para exibir.'
                )
                . self::operationEvidence(
                    'Webhooks recentes',
                    is_array($data['webhooks'] ?? null) ? $data['webhooks'] : [],
                    'Ainda não há eventos de webhook registrados para exibir.'
                ) . '</div>';

            $serviceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : 0;
            if ($serviceId > 0) {
                $body .= self::operationTimeline($base, $serviceId);
            }
        }
        $body .= '<div class="bcp-help-strip"><strong>Como interpretar:</strong> disponível significa que o módulo recebeu uma informação atual. Atenção indica que vale revisar antes de vender ou provisionar. Esta tela não expõe chaves, dados privados de clientes nem conteúdo de eventos.</div>';
        return self::card($h, self::tabs($base, 'operation') . $body);
    }

    private static function diagnosticsView(string $base): string
    {
        return DiagnosticsView::render($base);
    }

    /** @param list<string> $methods @return array<string,mixed> */
    private static function addonSnapshot(string $class, array $methods): array
    {
        if (!class_exists($class)) {
            return [];
        }
        foreach ($methods as $method) {
            if (!method_exists($class, $method)) {
                continue;
            }
            try {
                $value = $class::$method();
                if (is_array($value)) {
                    return $value;
                }
            } catch (\Throwable) {
                return [];
            }
        }
        return [];
    }

    private static function overviewStatusCard(
        string $label,
        string $status,
        string $detail,
        string $href,
        string $link,
        string $tone
    ): string {
        return '<section class="bcp-status-card is-' . self::e($tone) . '" role="listitem"><span class="bcp-status-dot" aria-hidden="true"></span>'
            . '<p>' . self::e($label) . '</p><h5>' . self::e($status) . '</h5><small>' . self::e($detail) . '</small>'
            . '<a href="' . self::e($href) . '">' . self::e($link) . '</a></section>';
    }

    private static function overviewStep(
        int $number,
        string $title,
        string $detail,
        bool $done,
        string $href,
        string $link
    ): string {
        return '<li class="bcp-onboarding-step' . ($done ? ' is-done' : '') . '"><span class="bcp-step-number" aria-label="Passo ' . $number . '">' . ($done ? self::ic('check') : $number) . '</span><div><h5>'
            . self::e($title) . ($done ? ' <span class="bcp-step-done">Concluído</span>' : '')
            . '</h5><p>' . self::e($detail) . '</p></div><a class="btn btn-default" href="' . self::e($href) . '">' . self::e($link) . '</a></li>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function snapshotLine(array $snapshot, string $key): string
    {
        $value = $snapshot[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string,mixed> $snapshot */
    private static function snapshotCards(array $snapshot): string
    {
        $items = $snapshot['items'] ?? $snapshot['checks'] ?? $snapshot['summary'] ?? [];
        if (!is_array($items)) {
            return '';
        }
        $cards = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? $item['name'] ?? 'Indicador'));
            $value = trim((string) ($item['value'] ?? $item['status'] ?? 'Indisponível'));
            $detail = trim((string) ($item['detail'] ?? $item['message'] ?? ''));
            $cards .= '<section class="bcp-operation-card"><p>' . self::e($label) . '</p><h5>' . self::e($value) . '</h5>'
                . ($detail !== '' ? '<small>' . self::e($detail) . '</small>' : '') . '</section>';
        }
        return $cards === '' ? '' : '<div class="bcp-operation-grid">' . $cards . '</div>';
    }

    /** @param array<string,mixed> $summary */
    private static function operationSummary(array $summary): string
    {
        $cards = [
            ['Serviços', (int) ($summary['total'] ?? 0), 'serviços BladeCP no WHMCS'],
            ['Ativos', (int) ($summary['active'] ?? 0), 'em operação normal'],
            ['Pendentes', (int) ($summary['pending'] ?? 0), 'aguardando conclusão'],
            ['Suspensos', (int) ($summary['suspended'] ?? 0), 'requerem revisão conforme necessário'],
            ['Atenção', (int) ($summary['attention'] ?? 0), 'pendentes, suspensos ou em análise'],
        ];
        $out = '';
        foreach ($cards as [$label, $value, $detail]) {
            $out .= '<section class="bcp-operation-card"><p>' . self::e($label)
                . '</p><h5>' . $value . '</h5><small>' . self::e($detail)
                . '</small></section>';
        }
        return '<div class="bcp-operation-grid bcp-operation-summary">' . $out . '</div>';
    }

    /** @param array<string,mixed> $filters */
    private static function operationFilters(string $base, array $filters): string
    {
        $status = (string) ($filters['status'] ?? '');
        $query = (string) ($filters['query'] ?? '');
        $perPage = (int) ($filters['perPage'] ?? 25);
        $statuses = ['', 'Pending', 'Active', 'Suspended', 'Terminated', 'Cancelled', 'Fraud'];
        $options = '';
        foreach ($statuses as $value) {
            $label = $value === '' ? 'Todos os status' : $value;
            $options .= '<option value="' . self::e($value) . '"'
                . ($status === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }
        $pages = '';
        foreach ([10, 25, 50] as $value) {
            $pages .= '<option value="' . $value . '"'
                . ($perPage === $value ? ' selected' : '') . '>' . $value . ' por página</option>';
        }
        return '<form method="get" action="' . self::e($base) . '" class="bcp-operation-filters" aria-label="Filtrar serviços BladeCP">'
            . '<input type="hidden" name="module" value="bladecp"><input type="hidden" name="action" value="operation">'
            . '<label for="bcp-operation-query"><span>Buscar</span><div class="bcp-fg-c"><input id="bcp-operation-query" type="search" name="query" value="' . self::e($query) . '" placeholder="Serviço, domínio ou produto"></div></label>'
            . '<label for="bcp-operation-status"><span>Status</span><div class="bcp-fg-c"><select id="bcp-operation-status" name="status">' . $options . '</select></div></label>'
            . '<label for="bcp-operation-per-page"><span>Exibição</span><div class="bcp-fg-c"><select id="bcp-operation-per-page" name="perPage">' . $pages . '</select></div></label>'
            . '<button class="btn btn-primary" type="submit">Aplicar filtros</button>'
            . '<a class="btn btn-default" href="' . self::e($base . '&action=operation') . '">Limpar</a></form>';
    }

    /** @param array<string,mixed> $services @param array<string,mixed> $filters */
    private static function operationServices(string $base, array $services, array $filters): string
    {
        $items = is_array($services['items'] ?? null) ? $services['items'] : [];
        $rows = '';
        foreach ($items as $service) {
            if (!is_array($service)) {
                continue;
            }
            $id = (int) ($service['serviceId'] ?? 0);
            $detail = $id > 0
                ? '<a class="btn btn-default" href="' . self::e(self::operationUrl($base, $filters, $id)) . '">Ver histórico</a>'
                : '';
            $rows .= '<tr><td><strong>#' . $id . '</strong><div class="bcp-csub">'
                . self::e((string) ($service['domain'] ?? '')) . '</div></td><td><strong>'
                . self::e((string) ($service['productName'] ?? '')) . '</strong><div class="bcp-csub">'
                . self::e((string) ($service['productType'] ?? '')) . ' · plano '
                . self::e((string) ($service['planId'] ?? '')) . '</div></td><td>'
                . self::serviceStatusBadge((string) ($service['status'] ?? 'Unknown')) . '</td><td>'
                . self::e((string) ($service['createdAt'] ?? '')) . '<div class="bcp-csub">próximo vencimento: '
                . self::e((string) ($service['nextDueDate'] ?? '')) . '</div></td><td class="bcp-operation-action">' . $detail . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="bcp-chips-empty">Nenhum serviço encontrado para este filtro.</td></tr>';
        }
        $table = '<section class="bcp-operation-section"><div class="bcp-section-heading"><div><h4>Serviços BladeCP</h4><p>Lista somente leitura, com dados reduzidos ao necessário para acompanhamento.</p></div></div><div class="bcp-tbl-wrap"><table class="bcp-tbl bcp-operation-table"><thead><tr><th scope="col">Serviço</th><th scope="col">Produto</th><th scope="col">Status</th><th scope="col">Datas</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';
        $page = max(1, (int) ($services['page'] ?? 1));
        $perPage = max(1, (int) ($services['perPage'] ?? 25));
        $total = max(0, (int) ($services['total'] ?? 0));
        $pages = max(1, (int) ceil($total / $perPage));
        if ($pages > 1) {
            $table .= '<div class="bcp-actions bcp-mkt-pager"><span class="bcp-row-v">Página ' . $page . ' de ' . $pages . ' · ' . $total . ' serviço(s)</span>'
                . ($page > 1 ? '<a class="btn btn-default" href="' . self::e(self::operationUrl($base, $filters, 0, $page - 1)) . '">Anterior</a>' : '')
                . ($page < $pages ? '<a class="btn btn-default" href="' . self::e(self::operationUrl($base, $filters, 0, $page + 1)) . '">Próxima</a>' : '')
                . '</div>';
        }
        return $table . '</section>';
    }

    /** @param array<string,mixed> $filters */
    private static function operationUrl(string $base, array $filters, int $serviceId = 0, int $page = 0): string
    {
        $query = trim((string) ($filters['query'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $perPage = max(10, min(50, (int) ($filters['perPage'] ?? 25)));
        $url = $base . '&action=operation&perPage=' . $perPage;
        if ($query !== '') {
            $url .= '&query=' . rawurlencode($query);
        }
        if ($status !== '') {
            $url .= '&status=' . rawurlencode($status);
        }
        if ($page > 0) {
            $url .= '&page=' . $page;
        }
        if ($serviceId > 0) {
            $url .= '&service_id=' . $serviceId;
        }
        return $url;
    }

    /** @param list<array<string,mixed>> $entries */
    private static function operationEvidence(string $title, array $entries, string $empty): string
    {
        $rows = '';
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $status = trim((string) ($entry['status'] ?? 'registrado'));
            $message = trim((string) ($entry['message'] ?? ''));
            $rows .= '<li><span class="bcp-diag-state' . ($status === 'failed' ? ' is-attention' : '') . '">' . self::e($status)
                . '</span><div><strong>' . self::e((string) ($entry['action'] ?? 'Evento BladeCP')) . '</strong>'
                . ($message !== '' ? '<p>' . self::e($message) . '</p>' : '')
                . '<small>' . self::e((string) ($entry['at'] ?? '')) . '</small></div></li>';
        }
        if ($rows === '') {
            $rows = '<li><span class="bcp-diag-state is-muted">sem dados</span><div><strong>' . self::e($empty) . '</strong></div></li>';
        }
        return '<section class="bcp-operation-section"><h4>' . self::e($title) . '</h4><ul class="bcp-diagnostic-list">' . $rows . '</ul></section>';
    }

    private static function operationTimeline(string $base, int $serviceId): string
    {
        $timeline = [];
        if (class_exists(Timeline::class)) {
            try {
                $timeline = Timeline::forService($serviceId);
            } catch (\Throwable) {
                $timeline = [];
            }
        }
        $service = is_array($timeline['service'] ?? null) ? $timeline['service'] : null;
        $items = is_array($timeline['items'] ?? null) ? $timeline['items'] : [];
        $title = $service === null
            ? 'Histórico do serviço'
            : 'Histórico do serviço #' . (int) ($service['serviceId'] ?? $serviceId);
        $rows = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rows .= '<li><span class="bcp-diag-state">' . self::e((string) ($item['kind'] ?? 'registrado')) . '</span><div><strong>'
                . self::e((string) ($item['title'] ?? 'Evento BladeCP')) . '</strong><p>'
                . self::e((string) ($item['detail'] ?? '')) . '</p><small>' . self::e((string) ($item['at'] ?? '')) . '</small></div></li>';
        }
        if ($rows === '') {
            $rows = '<li><span class="bcp-diag-state is-muted">sem eventos</span><div><strong>Não há evidências correlacionáveis disponíveis.</strong><p>A ausência de registros não confirma nem invalida uma operação.</p></div></li>';
        }
        return '<section class="bcp-operation-section bcp-operation-timeline"><div class="bcp-section-heading"><div><h4>' . self::e($title) . '</h4>'
            . ($service !== null ? '<p>' . self::e((string) ($service['productName'] ?? '')) . ' · ' . self::e((string) ($service['domain'] ?? '')) . '</p>' : '')
            . '</div><a class="btn btn-default" href="' . self::e($base . '&action=operation') . '">Fechar histórico</a></div><ul class="bcp-diagnostic-list">' . $rows . '</ul></section>';
    }

    private static function serviceStatusBadge(string $status): string
    {
        $tone = match ($status) {
            'Active' => 'is-ok',
            'Pending', 'Suspended', 'Fraud' => 'is-warn',
            'Terminated', 'Cancelled' => 'is-err',
            default => '',
        };
        return '<span class="bcp-badge ' . $tone . '">' . self::e($status) . '</span>';
    }

    /**
     * Aba "Planos": lista os planos do catálogo BladeCP (por categoria) e
     * importa as categorias (grupos) e os produtos com seus nomes.
     * Idempotente; nunca apaga. Fail-soft se não houver servidor/planos.
     */
    private static function plansView(string $base): string
    {
        $h = self::header(
            'Planos BladeCP',
            'Importa os planos do catálogo BladeCP como categorias e produtos no WHMCS, '
            . 'preservando seus nomes. Idempotente; nunca apaga.'
        );
        $tabs = self::tabs($base, 'planos');

        $flash = '';
        if (isset($_GET['imported'])) {
            $n = (int) $_GET['imported'];
            $flash = '<div class="bcp-badge is-ok">'
                . ($n === 1 ? '1 plano importado.' : $n . ' planos importados.')
                . '</div>';
        }
        if (isset($_GET['blocked']) && (int) $_GET['blocked'] > 0) {
            $n = (int) $_GET['blocked'];
            $flash .= '<div class="bcp-badge is-warn">' . $n
                . ' plano(s) foram ignorados porque a Customer API os '
                . 'marcou como não suportados.</div>';
        }
        if (isset($_GET['reordered'])) {
            $flash .= '<div class="bcp-badge is-ok">Catálogo reordenado ('
                . (int) $_GET['reordered'] . ' produto(s)) — a vitrine segue a '
                . 'ordem dos planos.</div>';
        }
        if (isset($_GET['linked'])) {
            $n = (int) $_GET['linked'];
            $flash .= '<div class="bcp-badge is-ok">'
                . ($n === 1 ? '1 produto vinculado' : $n . ' produtos vinculados')
                . ' aos componentes.</div>';
        }
        if (isset($_GET['nolinkgroup'])) {
            $flash .= '<div class="bcp-badge is-warn">Sincronize os componentes '
                . 'primeiro (aba Componentes) para poder vinculá-los aos produtos.</div>';
        }
        if (isset($_GET['modeset'])) {
            $n = (int) $_GET['modeset'];
            $flash .= '<div class="bcp-badge is-ok">Modo salvo'
                . ($n > 0 ? ' e aplicado a ' . $n . ' produto(s) existente(s)' : '')
                . '.</div>';
        }
        if (isset($_GET['catalog_mode_saved'])) {
            $saved = (int) $_GET['catalog_mode_saved'] === 1;
            $mode = (string) $_GET['catalog_mode'];
            $flash .= '<div class="bcp-badge ' . ($saved ? 'is-ok' : 'is-warn') . '">'
                . ($saved
                    ? 'Modo de disponibilidade salvo: '
                        . self::e($mode === 'enforce' ? 'aplicar e ocultar produtos bloqueados' : 'observar e proteger vendas')
                        . '.'
                    : 'Não foi possível salvar o modo de disponibilidade. A configuração anterior foi preservada.')
                . '</div>';
        }
        if (isset($_GET['reconciled'])) {
            $ok = (int) $_GET['reconciled'] === 1;
            $flash .= '<div class="bcp-badge ' . ($ok ? 'is-ok' : 'is-warn') . '">'
                . ($ok
                    ? (int) ($_GET['processed'] ?? 0) . ' produto(s) revisados, '
                        . (int) ($_GET['blocked_now'] ?? 0) . ' bloqueado(s) para novas vendas'
                        . ((int) ($_GET['hidden'] ?? 0) > 0
                            ? ', ' . (int) $_GET['hidden'] . ' ocultado(s) pela regra atual' : '') . '.'
                    : 'Não foi possível reconciliar o catálogo agora. Tente novamente após confirmar a conexão.')
                . '</div>';
        }
        if (isset($_GET['sale_released'])) {
            $released = (int) $_GET['sale_released'] === 1;
            $flash .= '<div class="bcp-badge ' . ($released ? 'is-ok' : 'is-warn') . '">'
                . ($released
                    ? 'Venda liberada após a reconciliação. O produto voltou para a vitrine.'
                    : 'A venda não foi liberada. Revise a disponibilidade atual e tente reconciliar o catálogo antes de liberar.')
                . '</div>';
        }

        $plans = PlanSync::plans();
        if ($plans === []) {
            return self::card($h, $tabs . $flash . '<p class="bcp-row-v">Nenhum plano '
                . 'carregado. Confira se o servidor BladeCP está conectado (aba '
                . '<strong>Configuração rápida</strong>) e se há planos disponíveis no catálogo BladeCP.</p>');
        }

        $existing = PlanSync::existingByPlan();
        $linkedPids = ConfigurableOptions::linkedProductIds();
        $hasCompGroup = ConfigurableOptions::groupId(false) !== null;
        $importCombo = Combos::importComboId();
        $catalogMode = class_exists(CatalogAvailabilitySync::class)
            ? CatalogAvailabilitySync::mode() : 'observe';
        $missing = 0;
        $blocked = 0;
        $unlinked = 0;
        $byGroup = [];
        $groupOrder = [];
        foreach ($plans as $p) {
            $byGroup[$p['group']][] = $p;
            $groupOrder[$p['group']] = $p['order'] ?? 9;
            $pidP = $existing[$p['id']] ?? 0;
            $supported = ($p['supported'] ?? false) === true;
            if (!$supported) {
                $blocked++;
            } elseif ($pidP === 0) {
                $missing++;
            } elseif (!isset($linkedPids[$pidP])) {
                $unlinked++;
            }
        }
        // Ordem canônica dos grupos (Hospedagem < Revenda < VPS < Nuvem
        // Pública < Dedicados); dentro do grupo, por id ascendente.
        uksort($byGroup, static fn ($a, $b) => ($groupOrder[$a] <=> $groupOrder[$b]) ?: strcmp((string) $a, (string) $b));
        foreach ($byGroup as &$grpRows) {
            usort($grpRows, static fn ($x, $y) => ((int) $x['id']) <=> ((int) $y['id']));
        }
        unset($grpRows);

        $actions = '<div class="bcp-actions" style="margin-bottom:16px">';
        if ($missing > 0) {
            $actions .= '<form method="post" action="' . self::e($base) . '" style="display:inline">'
                . self::token()
                . '<input type="hidden" name="action" value="plans-import-all">'
                . '<button class="btn btn-primary" type="submit">' . self::ic('download')
                . 'Importar ' . $missing . ' plano(s) que faltam</button></form>';
        } else {
            $actions .= '<span class="bcp-badge is-ok">Todos os planos '
                . 'suportados já estão no WHMCS.</span>';
        }
        if ($blocked > 0) {
            $actions .= ' <span class="bcp-badge is-warn">' . $blocked
                . ' plano(s) bloqueado(s) pelo contrato Customer API.</span>';
        }
        $actions .= ' <form method="post" action="' . self::e($base) . '" style="display:inline">'
            . self::token()
            . '<input type="hidden" name="action" value="plans-reorder">'
            . '<button class="btn btn-default" type="submit">' . self::ic('refresh')
            . 'Reordenar catálogo</button></form>';
        $actions .= ' <form method="post" action="' . self::e($base) . '" style="display:inline">'
            . self::token()
            . '<input type="hidden" name="action" value="catalog-reconcile">'
            . '<button class="btn btn-default" type="submit">' . self::ic('refresh')
            . 'Revisar disponibilidade</button></form>';
        if ($hasCompGroup && $unlinked > 0 && $importCombo === null) {
            $actions .= ' <form method="post" action="' . self::e($base) . '" style="display:inline">'
                . self::token()
                . '<input type="hidden" name="action" value="plans-link">'
                . '<button class="btn btn-default" type="submit">' . self::ic('plug')
                . 'Vincular componentes (' . $unlinked . ' sem vínculo)</button></form>';
        }
        $actions .= '</div>';

        $tables = '';
        foreach ($byGroup as $group => $rows) {
            $tr = '';
            foreach ($rows as $p) {
                $created = isset($existing[$p['id']]);
                $name = '<strong>' . self::e($p['name']) . '</strong>';
                $tr .= '<tr><td>' . $name . '</td>'
                    . '<td>' . ($p['desc'] !== '' ? self::e($p['desc']) : '—') . '</td>'
                    . self::planStatusCell(
                        $p,
                        $created,
                        $created ? (int) $existing[$p['id']] : 0,
                        $base
                    )
                    . '</tr>';
            }
            $tables .= '<h4 class="bcp-setup-h" style="margin:18px 0 8px">'
                . self::e($group) . '</h4>'
                . self::planGroupNotice($rows)
                . '<div class="bcp-tbl-wrap"><table class="bcp-tbl bcp-plans"><thead><tr><th scope="col">Plano</th><th scope="col">Recursos</th>'
                . '<th scope="col" style="text-align:right">Status</th></tr></thead><tbody>'
                . $tr . '</tbody></table></div>';
        }

        $note = '<p class="bcp-setup-hint" style="margin-top:16px">O produto nasce '
            . 'sem preço (você precifica na aba <strong>Tabela de preços</strong>). '
            . 'A descrição vem dos dados do plano só quando está vazia. Sua edição é '
            . 'preservada. Grupos e produtos nunca são apagados. Planos '
            . 'bloqueados vêm marcados pela Customer API e não podem ser '
            . 'importados até o contrato publicá-los como suportados.</p>';

        // Modo de importação: Composável (cliente escolhe) vs Simples
        // (combo fixo, sem opções no carrinho — pro mercado clássico).
        $combos = Combos::all();
        $modeForm = '';
        if ($combos !== []) {
            $cur = $importCombo;
            $opts = '<option value="0"' . ($cur === null ? ' selected' : '')
                . '>Composável — o cliente escolhe os componentes</option>';
            foreach ($combos as $c) {
                $opts .= '<option value="' . (int) $c['id'] . '"'
                    . ((int) $c['id'] === $cur ? ' selected' : '') . '>Simples — combo "'
                    . self::e($c['name']) . '" (componentes fixos, sem opções no carrinho)</option>';
            }
            $modeForm = '<form method="post" action="' . self::e($base) . '" '
                . 'style="margin:0 0 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">'
                . self::token()
                . '<input type="hidden" name="action" value="plans-set-mode">'
                . '<strong>Modo de importação:</strong>'
                . '<div class="bcp-fg-c bcp-mode-select">'
                . '<label class="sr-only" for="bcp-import-mode">Modo de importação</label><select id="bcp-import-mode" name="import_mode">' . $opts . '</select></div>'
                . '<button class="btn btn-default" type="submit">' . self::ic('check')
                . 'Salvar</button></form>';
        }

        $availabilityMode = '<section class="bcp-catalog-mode" aria-labelledby="bcp-catalog-mode-title"><div><span class="bcp-eyebrow">DISPONIBILIDADE DE VENDA</span><h4 id="bcp-catalog-mode-title">Como aplicar planos indisponíveis</h4><p>Nos dois modos, o módulo registra a decisão e bloqueia carrinho e provisionamento quando não for seguro vender. Não existe modo desligado.</p></div><form method="post" action="'
            . self::e($base) . '" class="bcp-catalog-mode-form">' . self::token()
            . '<input type="hidden" name="action" value="catalog-mode"><label for="bcp-catalog-mode">Modo</label><div class="bcp-fg-c bcp-catalog-mode-select"><select id="bcp-catalog-mode" name="catalog_mode">'
            . '<option value="observe"' . ($catalogMode === 'observe' ? ' selected' : '') . '>Observar: bloqueia carrinho e provisionamento, preserva a vitrine</option>'
            . '<option value="enforce"' . ($catalogMode === 'enforce' ? ' selected' : '') . '>Aplicar: bloqueia e também oculta produtos bloqueados</option>'
            . '</select></div><button class="btn btn-default" type="submit">Salvar modo</button></form></section>';

        return self::card($h, $tabs . $flash . $availabilityMode . $modeForm . $actions . $tables . $note);
    }

    /**
     * Exibe um único aviso por categoria, sem repetir o motivo técnico em cada
     * plano. O estado individual continua visível na coluna Status.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function planGroupNotice(array $rows): string
    {
        $blocked = 0;
        $reasons = [];
        foreach ($rows as $plan) {
            if (($plan['supported'] ?? false) !== true) {
                $blocked++;
                $reason = trim((string) ($plan['reason'] ?? ''));
                if ($reason === '') {
                    $reason = 'O catálogo não informou um motivo detalhado.';
                }
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        if ($blocked === 0) {
            return '';
        }

        $label = $blocked === 1
            ? '1 plano indisponível para provisionamento.'
            : $blocked . ' planos indisponíveis para provisionamento.';
        $reasonHtml = '';
        foreach ($reasons as $reason => $count) {
            $reasonHtml .= '<li>'
                . (count($reasons) > 1 ? '<strong>' . $count . ' plano(s):</strong> ' : '')
                . self::e($reason) . '</li>';
        }

        return '<div class="bcp-plan-group-note"><strong>' . $label . '</strong>'
            . '<span>Produtos existentes no WHMCS foram preservados.</span>'
            . '<ul>' . $reasonHtml . '</ul></div>';
    }

    /**
     * Mantém somente um estado visual por plano, evitando badges concorrentes
     * e overflow na coluna estreita.
     *
     * @param array<string,mixed> $plan
     */
    private static function planStatusCell(
        array $plan,
        bool $created,
        int $productId,
        string $base
    ): string {
        $supported = ($plan['supported'] ?? false) === true;
        $state = $productId > 0 ? CatalogStateRepository::find($productId) : null;
        $release = self::catalogReleaseControl($base, $productId, $state);
        if (!$supported) {
            $badge = '<span class="bcp-badge is-warn" title="Novos '
                . 'provisionamentos estão bloqueados para este plano">'
                . 'indisponível</span>';
            $productLink = $created && $productId > 0
                ? '<a class="bcp-plan-product-link" href="'
                    . self::e('configproducts.php?action=edit&id=' . $productId)
                    . '" aria-label="Abrir produto WHMCS ' . self::e($plan['name'] ?? '')
                    . '">Produto WHMCS</a>'
                : '';

            return '<td class="bcp-plan-status"><div class="bcp-plan-status-stack">'
                . $badge . $productLink . $release . '</div></td>';
        }

        if ($created && $productId > 0) {
            return '<td class="bcp-plan-status"><div class="bcp-plan-status-stack"><a class="bcp-plan-status-link" href="'
                . self::e('configproducts.php?action=edit&id=' . $productId)
                . '"><span class="bcp-badge is-ok">criado</span></a>' . $release
                . '</div></td>';
        }

        return '<td class="bcp-plan-status"><form method="post" action="'
            . self::e($base) . '" class="bcp-plan-import">'
            . self::token()
            . '<input type="hidden" name="action" value="plan-import">'
            . '<input type="hidden" name="plan" value="' . self::e($plan['id'] ?? '') . '">'
            . '<button class="btn btn-default" type="submit">' . self::ic('plus')
            . 'Importar</button></form></td>';
    }

    /** @param array<string,mixed>|null $state */
    private static function catalogReleaseControl(string $base, int $productId, ?array $state): string
    {
        if ($productId <= 0 || $state === null || empty($state['reconciliationPending'])) {
            return '';
        }

        return '<form method="post" action="' . self::e($base)
            . '" class="bcp-plan-release" onsubmit="return confirm(\'Confirmar a liberação deste produto para venda? A elegibilidade atual registrada será confirmada antes de reexibir a vitrine.\')">'
            . self::token()
            . '<input type="hidden" name="action" value="catalog-approve-sale">'
            . '<input type="hidden" name="pid" value="' . $productId . '">'
            . '<button class="btn btn-default" type="submit">Liberar venda</button></form>';
    }

    private static function listView(string $base, int $pid): string
    {
        $combos = Combos::all();
        $h = self::header(
            'Addon BladeCP',
            'Combos de componentes, sincronização do order form e '
                . 'tabela de preços. Credenciais ficam no Server record.'
        );

        $ctx = $pid > 0 ? self::productContext($base, $pid, $combos) : '';
        $flash = '';
        if (isset($_GET['saved'])) {
            $flash = '<div class="bcp-badge is-ok">Combo salvo.</div>';
        } elseif (isset($_GET['deleted'])) {
            $flash = '<div class="bcp-badge is-ok">Combo excluído.</div>';
        } elseif (isset($_GET['assigned'])) {
            $flash = '<div class="bcp-badge is-ok">Combo atribuído ao produto.</div>';
        }

        // Resolve os tokens `slug@versionId` salvos → nome do
        // componente + versão LEGÍVEL (mesma fonte do picker). Catálogo
        // indisponível (offline/sem Server record) → mapas vazios →
        // fallback pro token cru (nada regride).
        [$catalog, ] = self::catalog();
        $cName = [];
        $cVer = [];
        foreach ($catalog as $it) {
            $cName[$it['slug']] = $it['name'];
            foreach (($it['versions'] ?? []) as $v) {
                $cVer[$it['slug']][(int) $v['id']] = (string) $v['label'];
            }
        }

        $rows = '';
        foreach ($combos as $c) {
            $chips = '';
            foreach ($c['components'] as $s) {
                $r = ComponentRef::parse((string) $s);
                $slug = $r['slug'];
                $name = $cName[$slug] ?? '';
                $vlbl = ($r['version'] !== null
                    && isset($cVer[$slug][$r['version']]))
                    ? $cVer[$slug][$r['version']]
                    : null;
                if ($name === '') {
                    // catálogo não resolveu → token cru (sem perder o @v)
                    $chips .= '<span class="bcp-pill">'
                        . self::e((string) $s) . '</span> ';
                    continue;
                }
                $chips .= '<span class="bcp-pill">' . self::e($name)
                    . ($vlbl !== null
                        ? ' <span class="bcp-pill-v">'
                            . self::e($vlbl) . '</span>'
                        : '')
                    . '</span> ';
            }
            $rows .= '<tr><td><strong>' . self::e($c['name']) . '</strong>'
                . '<div class="bcp-csub">' . self::e($c['slug'])
                . '</div></td><td>' . ($chips !== '' ? $chips : '—')
                . '</td><td style="text-align:right;white-space:nowrap">'
                . '<a class="btn btn-default" href="' . self::e($base)
                . '&action=edit&id=' . (int) $c['id'] . '">' . self::ic('pencil') . 'Editar</a> '
                . self::postBtn(
                    $base,
                    'delete',
                    ['id' => (string) $c['id']],
                    'Excluir',
                    'Excluir o combo "' . $c['name'] . '"?'
                )
                . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="3" class="bcp-chips-empty">'
                . 'Nenhum combo ainda. Crie o primeiro.</td></tr>';
        }

        // "Sincronizar componentes"/"Tabela de preços" agora são TABS
        // (não botões soltos). Aqui fica só a ação primária da aba.
        $table = '<div class="bcp-actions"><a class="btn btn-primary" '
            . 'href="' . self::e($base) . '&action=new'
            . ($pid > 0 ? '&pid=' . $pid : '')
            . '">' . self::ic('plus') . 'Novo combo</a></div>'
            . '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Combo</th>'
            . '<th scope="col">Componentes</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>'
            . $rows . '</tbody></table></div>';

        return self::card($h, self::tabs($base, 'combos') . $ctx . $flash . $table);
    }

    private static function productContext(
        string $base,
        int $pid,
        array $combos
    ): string {
        $name = self::productName($pid);
        $current = Combos::comboIdForProduct($pid);
        $opts = '<option value="">— Nenhum (só componentes avulsos) —</option>';
        foreach ($combos as $c) {
            $sel = $current === $c['id'] ? ' selected' : '';
            $opts .= '<option value="' . (int) $c['id'] . '"' . $sel . '>'
                . self::e($c['name']) . '</option>';
        }
        return '<div class="bcp-prodctx"><div class="bcp-sec">'
            . 'Produto WHMCS</div><p class="bcp-row-v">Configurando o '
            . 'combo de <strong>' . self::e($name) . '</strong> '
            . '(#' . $pid . '). O provisionamento expande os '
            . 'componentes do combo + os avulsos do produto.</p>'
            . '<form method="post" action="' . self::e($base) . '">'
            . self::token()
            . '<input type="hidden" name="action" value="assign">'
            . '<input type="hidden" name="pid" value="' . $pid . '">'
            . '<div class="bcp-fg"><label class="bcp-fg-l" for="bcp-product-combo-id">'
            . 'Combo deste produto</label><div class="bcp-fg-c">'
            . '<select id="bcp-product-combo-id" name="combo_id">' . $opts . '</select></div></div>'
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit">' . self::ic('check') . 'Salvar atribuição</button>'
            . '<a class="btn btn-default" href="configproducts.php?action=edit&id='
            . $pid . '">' . self::ic('arrow-left')
            . 'Voltar ao produto</a></div></form>'
            . '<hr class="bcp-hr"></div>';
    }

    private static function formView(string $base, int $pid): string
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $combo = $id > 0 ? Combos::find($id) : null;
        $name = $combo['name'] ?? '';
        $selected = $combo['components'] ?? [];

        [$catalog, $catOk] = self::catalog();
        if ($catOk && $catalog !== []) {
            $picker = self::catalogPicker($catalog, $selected);
        } else {
            $picker = '<div class="bcp-fg"><label class="bcp-fg-l" for="bcp-components-csv">'
                . 'Componentes (slugs separados por vírgula)</label>'
                . '<div class="bcp-fg-c"><textarea id="bcp-components-csv" name="components_csv" '
                . 'rows="3" placeholder="php-8.2,mysql,redis">'
                . self::e(implode(',', $selected)) . '</textarea></div>'
                . '<small class="bcp-fg-d">Catálogo indisponível '
                . '(sem Server record/key/rede) — informe os slugs '
                . 'manualmente. São validados no provisionamento.</small>'
                . '</div>';
        }

        $h = self::header(
            $id > 0 ? 'Editar combo' : 'Novo combo',
            'Um combo agrupa slugs de componentes do catálogo BladeCP.'
        );
        $form = '<form method="post" action="' . self::e($base) . '">'
            . self::token()
            . '<input type="hidden" name="action" value="save">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . ($pid > 0
                ? '<input type="hidden" name="pid" value="' . $pid . '">'
                : '')
            . '<div class="bcp-fg"><label class="bcp-fg-l" for="bcp-combo-name">Nome</label>'
            . '<div class="bcp-fg-c"><input id="bcp-combo-name" type="text" name="name" '
            . 'value="' . self::e($name) . '" placeholder="Ex.: LAMP 8.2" '
            . 'required></div></div>' . $picker
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit">' . self::ic('check') . 'Salvar</button>'
            // Novo/editar combo é DRILL-DOWN da aba Combos (a aba
            // ativa não sinaliza "clique p/ voltar à lista") → mantém
            // "Voltar" p/ a lista. Com pid, volta ao produto.
            . ($pid > 0
                ? '<a class="btn btn-default" href="configproducts.php'
                    . '?action=edit&id=' . $pid . '">'
                    . self::ic('arrow-left') . 'Voltar ao produto</a>'
                : '<a class="btn btn-default" href="' . self::e($base)
                    . '">' . self::ic('arrow-left')
                    . 'Voltar aos combos</a>')
            . '</div></form>';

        return self::card($h, self::tabs($base, 'combos') . $form);
    }

    private static function catalogPicker(array $catalog, array $selected): string
    {
        // slug → versão pinada (do token salvo); presença = marcado.
        $pinned = [];
        foreach ($selected as $tok) {
            $r = ComponentRef::parse((string) $tok);
            if ($r['slug'] !== '') {
                $pinned[$r['slug']] = $r['version'];
            }
        }
        $groups = [];
        foreach ($catalog as $it) {
            $groups[$it['category']][] = $it;
        }
        // ordem de categoria definida pelo owner (fonte única).
        uksort($groups, static fn ($a, $b): int =>
            SelectionRules::categoryRank((string) $a)
                <=> SelectionRules::categoryRank((string) $b)
            ?: strcasecmp((string) $a, (string) $b));
        $body = '';
        foreach ($groups as $cat => $items) {
            $opts = '';
            foreach ($items as $it) {
                $slug = $it['slug'];
                $on = array_key_exists($slug, $pinned);
                $ck = $on ? ' checked' : '';
                $versions = $it['versions'] ?? [];
                // versão escolhida: a pinada salva, senão a default.
                $chosen = $on ? $pinned[$slug] : null;
                if ($chosen === null) {
                    foreach ($versions as $v) {
                        if ($v['is_default']) {
                            $chosen = $v['id'];
                            break;
                        }
                    }
                }
                // Select de versão SÓ quando há >1 versão. 1 versão
                // → badge (sem select) + hidden p/ pinar; 0 → slug.
                if (count($versions) > 1) {
                    $o = '';
                    foreach ($versions as $v) {
                        $s = ((int) $chosen === $v['id']) ? ' selected' : '';
                        $lbl = (string) $v['label']
                            . ($v['is_default'] ? ' (default)' : '');
                        $o .= '<option value="' . (int) $v['id'] . '"'
                            . $s . '>' . self::e($lbl) . '</option>';
                    }
                    $verSel = '<select class="bcp-cver" aria-label="Versão de '
                        . self::e((string) $it['name']) . '" name="cver['
                        . self::e($slug) . ']">' . $o . '</select>';
                } elseif (count($versions) === 1) {
                    $v0 = $versions[0];
                    $verSel = '<input type="hidden" name="cver['
                        . self::e($slug) . ']" value="' . (int) $v0['id']
                        . '"><span class="badge bcp-slug">'
                        . self::e((string) $v0['label']) . '</span>';
                } else {
                    $verSel = '<span class="badge bcp-slug">'
                        . self::e($slug) . '</span>';
                }
                $opts .= '<label class="bcp-chips-opt" data-cat="'
                    . self::e(strtolower((string) $cat)) . '" data-solo="'
                    . (SelectionRules::isSolo((string) $cat) ? '1' : '0')
                    . '"><input type="checkbox" '
                    . 'name="components[]" value="' . self::e($slug)
                    . '"' . $ck . '><span class="bcp-chips-on">'
                    . self::e($it['name']) . '</span>' . $verSel
                    . '</label>';
            }
            $body .= '<div class="bcp-pick-grp">' . self::e((string) $cat)
                . '</div><div class="bcp-pick-grid">' . $opts . '</div>';
        }
        return '<div class="bcp-fg"><span id="bcp-components-label" class="bcp-fg-l">Componentes'
            . '</span><div class="bcp-pick" role="group" aria-labelledby="bcp-components-label">' . $body . '</div>'
            . '<small class="bcp-fg-d">Catálogo global da BladeCP. A '
            . 'allowlist por produto é aplicada no provisionamento.'
            . '</small></div>';
    }

    // ── Importação opcional de preços, com diff e sem sincronização automática ──

    /** @return array<int,array{code:string,curId:int,cur:array}> */
    private static function matchedCurrencies(int $planId): array
    {
        $whmcs = Pricing::currencies();
        $byCode = [];
        foreach ($whmcs as $id => $code) {
            $byCode[$code] = $id;
        }
        $out = [];
        foreach (Pricing::planCurrencies($planId) as $cur) {
            if (!is_array($cur)) {
                continue;
            }
            $code = strtoupper(trim((string) ($cur['currency'] ?? '')));
            if ($code === '' || !isset($byCode[$code])) {
                continue;
            }
            $out[] = ['code' => $code, 'curId' => $byCode[$code], 'cur' => $cur];
        }
        return $out;
    }

    private static function pricingView(string $base, int $pid): string
    {
        $h = self::header(
            'Importar preço do plano BladeCP',
            'Pré-preenchimento opt-in. Revise o diff e confirme. '
                . 'Não há sync automático.'
        );
        $back = '<div class="bcp-actions"><a class="btn btn-default" '
            . 'href="configproducts.php?action=edit&id=' . $pid
            . '#tab=2">' . self::ic('arrow-left') . 'Voltar ao produto (aba Pricing)</a></div>';

        if ($pid <= 0) {
            return self::card($h, '<p class="bcp-row-v">Abra a partir '
                . 'de um produto.</p>' . $back);
        }
        $planId = Pricing::planIdForProduct($pid);
        if ($planId === null) {
            return self::card($h, '<p class="bcp-row-v">Configure o '
                . 'ConfigOption <strong>BladeCP Plan</strong> no produto '
                . 'antes de importar o preço.</p>' . $back);
        }
        $matched = self::matchedCurrencies($planId);
        if ($matched === []) {
            return self::card($h, '<p class="bcp-row-v">Não foi '
                . 'possível obter o preço do plano (verifique a conexão / '
                . 'Server record) ou nenhuma moeda do plano coincide com '
                . 'as moedas do WHMCS.</p>' . $back);
        }

        $done = isset($_GET['done'])
            ? '<div class="bcp-badge is-ok">Preço importado.</div>'
            : '';

        $blocks = '';
        $anyChange = false;
        foreach ($matched as $m) {
            $sug = PriceMap::suggested($m['cur']);
            $cur = Pricing::currentCycles($pid, $m['curId']);
            $diff = PriceMap::diff($sug['cycles'], $cur);
            $rows = '';
            foreach ($diff as $d) {
                if ($d['changed']) {
                    $anyChange = true;
                }
                $rows .= '<tr><td>' . self::e($d['label']) . '</td><td>'
                    . ($d['old'] === null
                        ? '<span class="bcp-row-v muted">—</span>'
                        : self::money($d['old'], $m['code']))
                    . '</td><td>' . self::money($d['new'], $m['code'])
                    . '</td><td>' . ($d['changed']
                        ? '<span class="bcp-pill on">muda</span>'
                        : '<span class="bcp-pill off">igual</span>')
                    . '</td></tr>';
            }
            $blocks .= '<div class="bcp-sec">' . self::e($m['code'])
                . '</div><div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Ciclo</th>'
                . '<th scope="col">WHMCS atual</th><th scope="col">Sugerido (plano)</th>'
                . '<th scope="col"><span class="sr-only">Comparação</span></th></tr></thead><tbody>' . $rows
                . '</tbody></table></div>';
        }

        $confirm = '<form method="post" action="' . self::e($base) . '">'
            . self::token()
            . '<input type="hidden" name="action" value="pricing-apply">'
            . '<input type="hidden" name="pid" value="' . $pid . '">'
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit"' . ($anyChange ? '' : ' disabled')
            . '>' . self::ic('check') . 'Confirmar import</button><a class="btn btn-default" '
            . 'href="configproducts.php?action=edit&id=' . $pid
            . '#tab=2">' . self::ic('arrow-left')
            . 'Cancelar</a></div></form>';

        $note = '<div class="bcp-chips-note">Grava só os ciclos que o '
            . 'plano define. Não altera setup fees nem ciclos fora do '
            . 'plano. Não desabilita nada. Sem sync contínuo.</div>';

        return self::card($h, $done . $blocks . $confirm . $note);
    }

    private static function applyPricing(int $pid): void
    {
        $planId = Pricing::planIdForProduct($pid);
        if ($planId === null) {
            return;
        }
        foreach (self::matchedCurrencies($planId) as $m) {
            $sug = PriceMap::suggested($m['cur']);
            Pricing::apply(
                $pid,
                $m['curId'],
                PriceMap::writable($sug['cycles'])
            );
        }
    }

    private static function money(float $v, string $code): string
    {
        return self::e($code . ' ' . number_format($v, 2, ',', '.'));
    }

    /**
     * Painel de preços: todos os produtos BladeCP, WHMCS × plataforma,
     * divergência destacada e sincronização manual por linha ou para todos os
     * divergentes. A tela é somente leitura e só grava por ação explícita,
     * reutilizando as mesmas garantias da importação. Fail-soft: sem preço da
     * plataforma, a coluna mostra "—" sem botão de importação.
     */
    private static function pricesView(string $base): string
    {
        $h = self::header(
            'Tabela de preços — WHMCS × plataforma',
            'Compara cada produto com o preço do plano na plataforma '
                . 'BladeCP. Sincronização é MANUAL: só grava ao clicar, '
                . 'nada automático. Preço de componente não entra aqui.'
        );
        $synced = isset($_GET['synced'])
            ? '<div class="bcp-badge is-ok">'
                . (int) $_GET['synced']
                . ' produto(s) sincronizado(s).</div>'
            : '';
        $products = Pricing::bladecpProducts();
        if ($products === []) {
            return self::card($h, self::tabs($base, 'prices')
                . $synced . '<p class="bcp-row-v">'
                . 'Nenhum produto usa o módulo BladeCP ainda.</p>');
        }

        // Todas as colunas de ciclo (mesmo sem preço). Ordem/rótulos
        // = fonte única PriceMap::LABELS (monthly…triennially).
        $cycles = \WHMCS\Module\Addon\Bladecp\PriceMap::LABELS;
        $nCyc = count($cycles);

        // Célula de 1 ciclo: plataforma (alvo) + WHMCS abaixo em
        // vermelho se diverge; "—" quando o lado não tem preço.
        $cmp = static function (
            array $plat,
            array $whmcs,
            string $cyc
        ): string {
            $lines = [];
            foreach ($plat as $code => $pc) {
                $pv = $pc[$cyc] ?? null;
                $wv = $whmcs[$code][$cyc] ?? null;
                if ($pv === null && $wv === null) {
                    $lines[] = '<span class="bcp-row-v muted">—</span>';
                    continue;
                }
                $top = $pv !== null
                    ? self::money((float) $pv, $code)
                    : '<span class="bcp-row-v muted">' . self::e($code)
                        . ' —</span>';
                $diff = $pv === null
                    ? $wv !== null
                    : ($wv === null
                        || abs((float) $wv - (float) $pv) >= 0.005);
                if ($diff) {
                    $top .= '<div class="bcp-cwhmcs">WHMCS: '
                        . ($wv !== null
                            ? self::money((float) $wv, $code)
                            : '—') . '</div>';
                }
                $lines[] = $top;
            }
            return implode('<br>', $lines);
        };

        $rows = '';
        $diverging = [];
        // Agrupa por categoria de PLANO (Hospedagem/Revenda/VPS/…), igual
        // à aba Planos — cruzando o planId do produto → categoryFor.
        $byGroup = [];
        $groupOrder = [];
        $planCat = [];
        foreach (PlanSync::plans() as $pl) {
            $planCat[(string) $pl['id']] = [
                'group' => (string) $pl['group'],
                'order' => (int) ($pl['order'] ?? 9),
            ];
        }
        $emit = static function (array $cat, string $tr) use (&$byGroup, &$groupOrder): void {
            $byGroup[$cat['group']][] = $tr;
            $groupOrder[$cat['group']] = $cat['order'];
        };
        foreach ($products as $p) {
            $pid = $p['pid'];
            $nameCell = '<strong>' . self::e($p['name']) . '</strong>';
            $planId = $p['planId'];
            if ($planId === null) {
                $emit(['group' => 'Sem plano associado', 'order' => 9999],
                    '<tr><td>' . $nameCell . '</td><td>—</td>'
                    . '<td colspan="' . $nCyc . '" class="bcp-row-v '
                    . 'muted">Sem BladeCP Plan configurado</td><td>'
                    . '<span class="bcp-badge is-warn">sem plano'
                    . '</span></td><td></td></tr>');
                continue;
            }
            $cat = $planCat[(string) $planId] ?? ['group' => 'Outros', 'order' => 9998];
            $matched = self::matchedCurrencies($planId);
            if ($matched === []) {
                $emit($cat, '<tr><td>' . $nameCell . '</td><td>#'
                    . $planId . '</td><td colspan="' . $nCyc . '" '
                    . 'class="bcp-row-v muted">Preço da plataforma '
                    . 'indisponível</td><td><span class="bcp-badge '
                    . 'is-warn">sem preço</span></td><td></td></tr>');
                continue;
            }
            $plat = [];
            $whmcs = [];
            $changed = false;
            foreach ($matched as $m) {
                $sug = PriceMap::suggested($m['cur']);
                $cyc = Pricing::currentCycles($pid, $m['curId']);
                foreach (PriceMap::diff($sug['cycles'], $cyc) as $d) {
                    if ($d['changed']) {
                        $changed = true;
                    }
                }
                $plat[$m['code']] = $sug['cycles'];
                $whmcs[$m['code']] = $cyc;
            }
            $badge = $changed
                ? '<span class="bcp-badge is-err">divergente</span>'
                : '<span class="bcp-badge is-ok">sincronizado</span>';
            $act = '';
            if ($changed) {
                $diverging[] = $pid;
                $act = self::postBtn(
                    $base,
                    'prices-sync',
                    ['pids[]' => (string) $pid],
                    'Sincronizar',
                    'Importar o preço do plano BladeCP para "'
                        . $p['name'] . '" (sobrescreve só os ciclos '
                        . 'que o plano define)?',
                    'refresh'
                );
            }
            $cycCells = '';
            foreach ($cycles as $ckey => $clabel) {
                $cycCells .= '<td>' . $cmp($plat, $whmcs, $ckey)
                    . '</td>';
            }
            $emit($cat, '<tr><td>' . $nameCell . '</td><td>#' . $planId
                . '</td>' . $cycCells . '<td>' . $badge
                . '</td><td style="text-align:right">' . $act
                . '</td></tr>');
        }

        // Monta $rows na ordem canônica dos grupos, com cabeçalho por
        // categoria (linha full-width, igual à aba Planos).
        uksort($byGroup, static fn ($a, $b) =>
            ($groupOrder[$a] <=> $groupOrder[$b]) ?: strcmp((string) $a, (string) $b));
        $colspan = $nCyc + 4;
        foreach ($byGroup as $group => $trs) {
            $rows .= '<tr class="bcp-price-group"><td colspan="' . $colspan
                . '">' . self::e($group) . '</td></tr>' . implode('', $trs);
        }

        $bulk = '';
        if ($diverging !== []) {
            $hidden = '';
            foreach ($diverging as $n) {
                $hidden .= '<input type="hidden" name="pids[]" value="'
                    . (int) $n . '">';
            }
            $msg = 'Sincronizar o preço do plano para os '
                . count($diverging) . ' produto(s) divergente(s)? '
                . 'Sobrescreve só os ciclos que cada plano define.';
            $bulk = '<div class="bcp-actions"><form method="post" '
                . 'action="' . self::e($base) . '" onsubmit="return '
                . 'confirm(' . htmlspecialchars(
                    json_encode($msg, JSON_THROW_ON_ERROR),
                    ENT_QUOTES,
                    'UTF-8'
                ) . ')" style="display:inline">' . self::token()
                . '<input type="hidden" name="action" '
                . 'value="prices-sync">' . $hidden
                . '<button class="btn btn-primary" type="submit">'
                . self::ic('refresh') . 'Sincronizar todos os '
                . 'divergentes (' . count($diverging) . ')</button>'
                . '</form></div>';
        }

        $cycHead = '';
        foreach ($cycles as $clabel) {
            $cycHead .= '<th scope="col">' . self::e($clabel) . '</th>';
        }
        $legend = '<p class="bcp-chips-note">Cada célula mostra o '
            . 'preço da <strong>plataforma</strong>; quando o WHMCS '
            . 'diverge, o valor atual dele aparece em '
            . '<span class="bcp-cwhmcs" style="display:inline">'
            . 'vermelho</span> abaixo. "—" = sem preço naquele ciclo. '
            . 'Sincronizar grava só os ciclos que o plano define.</p>';
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Produto</th>'
            . '<th scope="col">Plano</th>' . $cycHead . '<th scope="col">Status</th>'
            . '<th scope="col"><span class="sr-only">Ações</span></th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>';

        return self::card($h, self::tabs($base, 'prices')
            . $synced . $legend . $bulk . $table);
    }

    // ── G — sync order form (Configurable Options) ──

    private static function orderformView(string $base, int $pid): string
    {
        $h = self::header(
            'Sincronizar componentes',
            'Sincroniza o catálogo BladeCP para o cliente escolher '
                . 'componentes/combo no checkout do WHMCS. Opt-in.'
        );
        // Sem "Voltar" genérico (a tab já navega). Só retorno ao
        // produto quando veio de um produto (pid — drill-down).
        $back = $pid > 0
            ? '<div class="bcp-actions"><a class="btn btn-default" '
                . 'href="configproducts.php?action=edit&id=' . $pid
                . '">' . self::ic('arrow-left')
                . 'Voltar ao produto</a></div>'
            : '';

        [$catalog, $catOk] = self::catalog();
        if (!$catOk || $catalog === []) {
            return self::card($h, self::tabs($base, 'sync')
                . '<p class="bcp-row-v">Catálogo '
                . 'indisponível (configure o <strong>Server record</strong> '
                . 'com a API key e Test Connection OK). Sem catálogo não '
                . 'dá pra sincronizar o order form.</p>' . $back);
        }

        $combos = Combos::all();
        $locations = self::locations();
        $spec = ConfigurableOptions::desiredSpec(
            $catalog,
            $combos,
            $locations
        );
        $desiredNames = array_map(
            static fn ($o) => $o['name'],
            $spec['options']
        );
        $current = ConfigurableOptions::currentOptionNames();
        $d = ConfigurableOptions::diffSpec($desiredNames, $current);
        $linked = $pid > 0 && ConfigurableOptions::isLinked($pid);

        $banner = '';
        if (isset($_GET['done'])) {
            $banner = '<div class="bcp-badge is-ok">Order form '
                . 'sincronizado.</div>';
        } elseif (isset($_GET['removed'])) {
            $banner = '<div class="bcp-badge is-warn">Sync removido '
                . 'do WHMCS.</div>';
        }
        // Aviso: moeda(s) sem preço sincronizado nas sub-options → o
        // WHMCS esconde os Configurable Options nessas moedas no order
        // form até re-sincronizar. "Sincronizar agora" abaixo corrige.
        $missCur = ConfigurableOptions::currenciesMissingPricing();
        if ($missCur !== []) {
            $banner .= '<div class="bcp-badge is-err">'
                . count($missCur) . ' moeda(s) sem preço sincronizado'
                . ' (' . self::e(implode(', ', $missCur)) . '): os '
                . 'Configurable Options não aparecem no order form '
                . 'nessas moedas. Clique em "Sincronizar agora" '
                . 'abaixo para corrigir.</div>';
        }

        // nome do componente → categoria (p/ agrupar o diff).
        $nameCat = [];
        foreach ($catalog as $it) {
            $n = trim((string) ($it['name'] ?? ''));
            if ($n !== '') {
                $nameCat[$n] = trim((string) ($it['category'] ?? 'Outros'))
                    ?: 'Outros';
            }
        }
        // lista de nomes → badges Bootstrap AGRUPADOS por categoria.
        $grouped = static function (array $xs) use ($nameCat): string {
            if ($xs === []) {
                return '<span class="bcp-row-v muted">—</span>';
            }
            $byCat = [];
            foreach ($xs as $x) {
                $byCat[$nameCat[$x] ?? 'Outros'][] = $x;
            }
            uksort($byCat, static fn ($a, $b): int =>
                SelectionRules::categoryRank((string) $a)
                    <=> SelectionRules::categoryRank((string) $b)
                ?: strcasecmp((string) $a, (string) $b));
            $o = '';
            foreach ($byCat as $cat => $names) {
                sort($names);
                $b = '';
                foreach ($names as $n) {
                    $b .= '<span class="badge">' . self::e($n) . '</span>';
                }
                $o .= '<div class="bcp-pick-grp">' . self::e((string) $cat)
                    . '</div><div class="bcp-badges">' . $b . '</div>';
            }
            return $o;
        };

        $status = '<div class="bcp-sec">Situação</div>'
            . '<div class="bcp-statrow"><span class="bcp-statk">'
            . 'Grupo "BladeCP" no WHMCS</span>'
            . ($current === []
                ? '<span class="bcp-pill off">ainda não criado</span>'
                : '<span class="bcp-pill on">existe ('
                    . count($current) . ' opções)</span>')
            . '</div>'
            . ($pid > 0
                ? '<div class="bcp-statrow"><span class="bcp-statk">'
                    . 'Ligado a este produto (#' . $pid . ')</span>'
                    . ($linked
                        ? '<span class="bcp-pill on">sim</span>'
                        : '<span class="bcp-pill off">não</span>')
                    . '</div>'
                : '');

        $preview = '<div class="bcp-sec">O que o sync vai fazer</div>'
            . '<div class="bcp-difb"><strong>Adicionar ('
            . count($d['add']) . ')</strong>' . $grouped($d['add'])
            . '</div><div class="bcp-difb"><strong>Manter ('
            . count($d['keep']) . ')</strong>' . $grouped($d['keep'])
            . '</div><div class="bcp-difb"><strong>Remover — só do '
            . 'nosso grupo (' . count($d['remove']) . ')</strong>'
            . $grouped($d['remove']) . '</div>';

        // ATENÇÃO: forms NÃO podem aninhar (HTML inválido — o browser
        // quebra e o submit dispara a action errada). apply e remove
        // são forms IRMÃOS dentro de .bcp-actions, não aninhados.
        $sync = '<div class="bcp-actions">'
            . '<form method="post" action="' . self::e($base)
            . '" style="display:inline">'
            . self::token()
            . '<input type="hidden" name="action" value="orderform-apply">'
            . ($pid > 0
                ? '<input type="hidden" name="pid" value="' . $pid . '">'
                : '')
            . '<button class="btn btn-primary" type="submit">'
            . self::ic('refresh') . 'Sincronizar agora'
            . ($pid > 0 ? ' + ligar a este produto' : '')
            . '</button></form>'
            . self::postBtn(
                $base,
                'orderform-remove',
                $pid > 0 ? ['pid' => (string) $pid] : [],
                'Remover do WHMCS',
                'Remover o grupo BladeCP e todas as opções/preços '
                    . 'sincronizados? (reversível: é só sincronizar de novo)'
            )
            . ($pid > 0
                ? '<a class="btn btn-default" href="configproducts.php'
                    . '?action=edit&id=' . $pid . '">'
                    . self::ic('arrow-left') . 'Voltar ao produto</a>'
                : '')
            . '</div>';

        $note = '<div class="bcp-chips-note">Cria 1 opção sim/não por '
            . 'componente + 1 dropdown "Combo". Preço nasce 0 — '
            . '<strong>você precifica no WHMCS</strong> (o catálogo não '
            . 'tem preço). Idempotente e reversível: só mexe no grupo '
            . '"BladeCP", nunca em opções suas. Sem sync automático.</div>';

        return self::card($h, self::tabs($base, 'sync')
            . $banner . $status . $preview . $sync . $note);
    }

    // ── Opções configuráveis precificadas ──

    /**
     * Preview + sync das opções configuráveis (grupos precificados do
     * catálogo). Diferente de "Sincronizar componentes", aqui o
     * preço VEM do catálogo (UNIDADE, por moeda × ciclo) e é gravado no
     * WHMCS — diferente dos componentes (preço 0, partner precifica).
     *
     * Associação por plano: aberto
     * a partir de um produto (`?pid`), lista os grupos ligados ao PLANO
     * mapeado no produto (ConfigOption "BladeCP Plan") e o checkbox liga
     * o grupo do plano ao produto WHMCS (representação WHMCS-side do
     * vínculo plano↔grupo). Sem produto → visão global (todos os grupos
     * do parceiro).
     */
    private static function optionsView(string $base, int $pid): string
    {
        $h = self::header(
            'Opções configuráveis',
            'Sincroniza grupos de opções precificadas (RAM, disco, …) do '
                . 'catálogo BladeCP para o order form do WHMCS. Os grupos '
                . 'são ligados ao PLANO do produto e não fazem parte '
                . 'da sincronização de componentes.'
        );
        [$groups, $ok] = self::optionGroups($pid);
        if (!$ok) {
            return self::card($h, self::tabs($base, 'options')
                . '<p class="bcp-row-v">Catálogo de opções indisponível '
                . '(configure o <strong>Server record</strong> com a API '
                . 'key e Test Connection OK). Sem catálogo não dá pra '
                . 'sincronizar as opções.</p>');
        }

        $banner = '';
        if (isset($_GET['done'])) {
            $banner = '<div class="bcp-badge is-ok">Opções '
                . 'sincronizadas.</div>';
        } elseif (isset($_GET['removed'])) {
            $banner = '<div class="bcp-badge is-warn">Opções removidas '
                . 'do WHMCS.</div>';
        }
        $miss = OptionGroups::currenciesMissingPricing();
        if ($miss !== []) {
            $banner .= '<div class="bcp-badge is-err">' . count($miss)
                . ' moeda(s) sem preço sincronizado ('
                . self::e(implode(', ', $miss)) . '): as opções não '
                . 'aparecem no order form nessas moedas até '
                . 're-sincronizar.</div>';
        }

        if ($groups === []) {
            $emptyMsg = $pid > 0
                ? 'Este produto (o PLANO mapeado nele) ainda não tem '
                    . 'grupos de opções configuráveis ligados no catálogo '
                    . 'BladeCP. Vincule grupos ao plano no catálogo BladeCP.'
                : 'O parceiro ainda não tem grupos de opções '
                    . 'configuráveis no catálogo BladeCP.';
            return self::card($h, self::tabs($base, 'options') . $banner
                . '<p class="bcp-row-v">' . self::e($emptyMsg) . '</p>');
        }

        $owned = OptionGroupsMap::allGroups();
        $rows = '';
        foreach ($groups as $g) {
            $uuid = (string) ($g['uuid'] ?? '');
            $gid = $owned[$uuid] ?? null;
            $synced = $gid !== null;
            $linked = $pid > 0 && $gid !== null
                && OptionGroups::isLinked($gid, $pid);
            $link = '';
            if ($pid > 0) {
                $ck = $linked ? ' checked' : '';
                $link = ' <label class="bcp-row-v" style="display:inline">'
                    . '<input type="checkbox" name="link[]" value="'
                    . self::e($uuid) . '"' . $ck . '> ligar a este '
                    . 'produto</label>';
            }
            $rows .= '<div class="bcp-difb"><strong>'
                . self::e((string) ($g['name'] ?? '')) . '</strong> '
                . ($synced
                    ? '<span class="bcp-pill on">sincronizado</span>'
                    : '<span class="bcp-pill off">novo</span>')
                . $link . self::optionsTable($g) . '</div>';
        }

        // ATENÇÃO: os checkboxes link[] precisam estar DENTRO do form de
        // sync. Form de remoção é IRMÃO (forms não aninham).
        $sync = '<form method="post" action="' . self::e($base) . '">'
            . self::token()
            . '<input type="hidden" name="action" value="options-apply">'
            . ($pid > 0
                ? '<input type="hidden" name="pid" value="' . $pid . '">'
                : '')
            . $rows
            . '<div class="bcp-actions"><button class="btn btn-primary" '
            . 'type="submit">' . self::ic('refresh') . 'Sincronizar opções'
            . ($pid > 0 ? ' (e vínculos marcados)' : '')
            . '</button></div></form>'
            . '<div class="bcp-actions">' . self::postBtn(
                $base,
                'options-remove',
                $pid > 0 ? ['pid' => (string) $pid] : [],
                'Remover do WHMCS',
                'Remover todos os grupos de opções configuráveis '
                    . 'sincronizados? (reversível: é só sincronizar de '
                    . 'novo)'
            ) . '</div>';

        $note = '<div class="bcp-chips-note">1 grupo do catálogo = 1 '
            . 'grupo de Configurable Options no WHMCS (sem clonagem por '
            . 'produto). Os grupos listados são os ligados ao <strong>'
            . 'plano</strong> do produto; o vínculo liga o grupo do plano '
            . 'a este produto WHMCS. O preço vem do catálogo (UNIDADE, '
            . 'por moeda × ciclo) e é <strong>sobrescrito</strong> a cada '
            . 'sync. O valor default "Nenhum (incluído)" fica a 0. '
            . 'Idempotente e reversível: só mexe nos grupos do BladeCP.'
            . '</div>';

        return self::card($h, self::tabs($base, 'options')
            . $banner . $sync . $note);
    }

    /** Tabela de opções×valores×preço de um grupo (preview). */
    private static function optionsTable(array $g): string
    {
        $opts = is_array($g['options'] ?? null) ? $g['options'] : [];
        if ($opts === []) {
            return '<p class="bcp-row-v muted">Sem opções neste grupo.</p>';
        }
        $out = '';
        foreach ($opts as $o) {
            if (!is_array($o)) {
                continue;
            }
            $vals = is_array($o['values'] ?? null) ? $o['values'] : [];
            $cells = '';
            foreach ($vals as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $def = !empty($v['isDefault'])
                    ? ' <span class="bcp-pill off">incluído</span>' : '';
                $cells .= '<tr><td>' . self::e((string) ($v['label'] ?? ''))
                    . $def . '</td><td>' . self::valuePriceLabel($v)
                    . '</td></tr>';
            }
            if ($cells === '') {
                continue;
            }
            $rk = $o['resourceKey'] ?? null;
            $out .= '<div class="bcp-sec">'
                . self::e((string) ($o['name'] ?? ''))
                . ($rk !== null
                    ? ' <span class="bcp-csub">' . self::e((string) $rk)
                        . '</span>'
                    : '')
                . '</div><div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Valor</th>'
                . '<th scope="col">Preço (mensal por moeda)</th></tr></thead><tbody>'
                . $cells . '</tbody></table></div>';
        }
        return $out;
    }

    /** Preço mensal por moeda de uma OptionValue (preview). */
    private static function valuePriceLabel(array $v): string
    {
        $curs = is_array($v['currencies'] ?? null) ? $v['currencies'] : [];
        if (!empty($v['isDefault']) || $curs === []) {
            return '<span class="bcp-row-v muted">—</span>';
        }
        $parts = [];
        foreach ($curs as $c) {
            if (!is_array($c)) {
                continue;
            }
            $code = strtoupper((string) ($c['currency'] ?? ''));
            $m = $c['priceMonthly'] ?? null;
            if ($code === '' || !is_numeric($m)) {
                continue;
            }
            $parts[] = self::money((float) $m, $code);
        }
        return $parts === []
            ? '<span class="bcp-row-v muted">—</span>'
            : implode(' · ', $parts);
    }

    /**
     * @return array{0:array,1:bool} grupos de opções + ok
     *
     * Associação por plano: aberto a partir de um produto
     * (`$pid`), resolve o `partner_plan_id` (ConfigOption "BladeCP
     * Plan") e lê o catálogo DO PLANO (`groupsForPlan`) — só os grupos
     * ligados àquele plano. Sem produto (visão global da aba) → todos
     * os grupos do parceiro (`groupsFor`). Sem plano configurado no
     * produto → fail-soft p/ a visão global.
     */
    private static function optionGroups(int $pid = 0): array
    {
        try {
            if (!class_exists(ServerConfig::class)) {
                return [[], false];
            }
            $client = ServerConfig::client();
            if ($client === null) {
                return [[], false];
            }
            $planId = $pid > 0 ? Pricing::planIdForProduct($pid) : null;
            $groups = $planId !== null
                ? OptionCatalog::groupsForPlan($client, $planId)
                : OptionCatalog::groupsFor($client);
            return [$groups ?? [], $groups !== null];
        } catch (\Throwable) {
            return [[], false];
        }
    }

    // ── Canal Marketplace, somente leitura e sem sincronização ──

    /**
     * Pedidos vendidos que a BladeCP faturou e atribuiu ao partner
     * (bloco 8 do plano marketplace). Análogo direto de `pricesView`:
     * puxa dado seller-scoped read-only da Customer API com a `bcp_*`
     * key do Server record e renderiza. 100% leitura — NENHUMA escrita
     * em `tblpricing/tblinvoices/tblorders`. Dinheiro em CENTAVOS
     * (÷100 só aqui na renderização; nunca soma moedas diferentes).
     */
    /**
     * Aba Marketplace unificada: o corpo (esquerda) traz os pedidos
     * vendidos + as tabelas financeiras (substrato, extratos); o sidebar
     * (direita) traz os números que eram a aba "Minha conta" — repasse,
     * cobertura/break-even e catálogo. Tudo read-only; cada seção é
     * fail-soft sozinha (um endpoint fora não derruba os outros).
     */
    private static function marketplaceView(string $base): string
    {
        return MarketplaceView::render($base);
    }

    // ── Conectar webhooks ──

    /** Registra o receiver sem revelar o segredo em nenhuma resposta HTML. */
    private static function webhooksView(
        string $base,
        string $error = '',
        string $success = ''
    ): string {
        return WebhooksView::render($base, $error, $success);
    }
    private static function setupView(
        string $base,
        string $error = '',
        bool $forceEdit = false
    ): string {
        return SetupView::render($base, $error, $forceEdit);
    }
    private static function serverContextCard(string $base): string
    {
        $context = AdminServerContext::resolve();
        $state = (string) ($context['state'] ?? 'missing');
        $server = is_array($context['server'] ?? null) ? $context['server'] : null;
        if ($state !== 'configured' || $server === null) {
            return '';
        }

        $name = trim((string) ($server['name'] ?? 'Servidor BladeCP'));
        $host = trim((string) ($server['hostname'] ?? ''));
        $normalizedHost = strtolower($host);
        if (str_contains($normalizedHost, Setup::HOST_SANDBOX)) {
            $environment = 'Sandbox';
            $environmentClass = 'is-sandbox';
        } elseif (str_contains($normalizedHost, Setup::HOST_PRODUCTION)) {
            $environment = 'Produção';
            $environmentClass = 'is-production';
        } else {
            $environment = 'Personalizado';
            $environmentClass = 'is-custom';
        }
        $displayName = preg_replace(
            '/\s*\((?:sandbox|produção|producao|production)\)\s*$/iu',
            '',
            $name
        );
        $displayName = trim(is_string($displayName) ? $displayName : $name);

        return '<aside class="bcp-server-context-card" aria-label="Servidor BladeCP em uso">'
            . '<span class="bcp-server-context-details">'
            . '<span class="bcp-server-context-label">Servidor</span>'
            . '<span class="bcp-server-context-identity">'
            . '<strong class="bcp-server-context-name">' . self::e($displayName !== '' ? $displayName : 'BladeCP') . '</strong>'
            . '<span class="bcp-badge bcp-server-context-environment ' . $environmentClass . '">'
            . self::e($environment) . '</span></span>'
            . ($host !== '' ? '<code class="bcp-server-context-host">' . self::e($host) . '</code>' : '')
            . '</span>'
            . '<a class="btn btn-default btn-sm bcp-server-context-action" href="'
            . self::e($base . '&action=setup') . '">' . self::ic('pencil')
            . 'Revisar configuração</a></aside>';
    }

    /**
     * Alerta no topo das abas enquanto não existe um contexto válido.
     * Vazio quando o servidor já está configurado.
     */
    private static function setupBanner(string $base): string
    {
        $context = AdminServerContext::resolve();
        $state = (string) ($context['state'] ?? 'missing');
        if ($state === 'configured') {
            return '';
        }

        $label = $state === 'ambiguous'
            ? 'Há mais de um servidor BladeCP elegível. Escolha o servidor usado pelas telas globais.'
            : ($state === 'invalid'
                ? 'A conta BladeCP antes selecionada não está mais disponível.'
                : 'Servidor BladeCP ainda não configurado. Conecte sua API key em um passo.');
        return '<div class="bcp-flash bcp-flash-warn"><div><strong>' . self::e($label)
            . '</strong></div><a class="btn btn-primary" href="' . self::e($base)
            . '&action=setup">' . self::ic('plug')
            . ($state === 'missing' ? 'Configuração rápida' : 'Escolher conta') . '</a></div>';
    }

    /**
     * POST do wizard: detecta ambiente → valida (GET /me) → grava o
     * Server record. Só grava se conectar. A chave nunca é refletida no
     * HTML nem logada; vai só pro Server record (encriptada).
     */
    private static function handleSetupSave(string $base): string
    {
        $context = AdminServerContext::resolve();
        if (in_array((string) ($context['state'] ?? ''), ['ambiguous', 'invalid'], true)) {
            return self::shell(self::setupView(
                $base,
                'Escolha uma conta BladeCP válida antes de salvar uma credencial. Isso evita criar ou alterar um servidor diferente por engano.',
                true
            ));
        }
        $key = trim((string) ($_POST['apikey'] ?? ''));
        $env = Setup::detectEnv($key);
        if ($env === null) {
            return self::shell(self::setupView(
                $base,
                'Chave não reconhecida. Use uma chave bcp_sand_… para sandbox '
                . 'ou bcp_live_… para produção.',
                true
            ));
        }

        $val = Setup::validate($key, $env['host']);
        if (!$val['ok']) {
            return self::shell(self::setupView(
                $base,
                'Não foi possível conectar com essa chave em ' . $env['label']
                . '. ' . (string) ($val['error'] ?? ''),
                true
            ));
        }

        $save = Setup::saveServer($key, $env);
        if (!$save['ok']) {
            return self::shell(self::setupView(
                $base,
                'A conexão foi validada, mas a credencial não pôde ser '
                . 'protegida e nenhuma alteração foi salva. Verifique a '
                . 'criptografia do WHMCS e tente novamente.',
                true
            ));
        }

        return self::redirect($base . '&action=setup&ok=' . $save['mode']);
    }

    /**
     * Testa a conexão usando somente a credencial já protegida no Server
     * record. Nenhum segredo é devolvido ao HTML ou à URL.
     */
    private static function handleSetupTest(string $base): string
    {
        $params = ServerConfig::params();
        if ($params === null) {
            return self::shell(self::setupView(
                $base,
                'Nenhum servidor BladeCP configurado foi encontrado.'
            ));
        }

        $key = trim((string) ($params['serveraccesshash'] ?? ''));
        $host = trim((string) ($params['serverhostname'] ?? ''));
        if ($key === '') {
            return self::shell(self::setupView(
                $base,
                'O servidor existe, mas não possui uma credencial BladeCP válida.'
            ));
        }
        if ($host === '') {
            $host = Setup::HOST_PRODUCTION;
        }

        $validation = Setup::validate($key, $host);
        if (!$validation['ok']) {
            return self::shell(self::setupView(
                $base,
                'A configuração está salva, mas a conexão não pôde ser validada. '
                . (string) ($validation['error'] ?? '')
            ));
        }

        return self::redirect($base . '&action=setup&ok=tested');
    }

    private static function redirect(string $to): string
    {
        return '<script>window.location='
            . json_encode($to, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ';</script><noscript><a href="' . self::e($to)
            . '">Continuar</a></noscript>';
    }

    private static function handlePost(string $base, string $action, int $pid): string
    {
        if (!self::checkToken()) {
            return self::shell(self::card(
                self::header('Sessão expirada', ''),
                '<p class="bcp-row-v">Token inválido. '
                . '<a href="' . self::e($base) . '">Voltar</a>.</p>'
            ));
        }
        if ($action === 'admin-server-select') {
            $selected = (int) ($_POST['server_id'] ?? -1);
            $ok = false;
            if ($selected === 0) {
                $ok = AdminServerContext::clearSelectedId();
            } elseif ($selected > 0) {
                $ok = AdminServerContext::setSelectedId($selected);
            }
            if (!$ok) {
                return self::shell(self::setupView(
                    $base,
                    'Não foi possível salvar o servidor BladeCP escolhido. Selecione um servidor elegível e tente novamente.'
                ));
            }
            return self::redirect($base . '&action=setup&context=updated');
        }
        if ($action === 'catalog-approve-sale') {
            $productId = (int) ($_POST['pid'] ?? 0);
            return self::redirect($base . '&action=planos&sale_released='
                . (self::approveCatalogSale($productId) ? '1' : '0'));
        }
        if ($action === 'save') {
            $components = $_POST['components'] ?? ($_POST['components_csv'] ?? []);
            // Picker: components[] = slugs marcados; cver[slug] =
            // versão escolhida → token slug@versionId (pin explícito).
            // Fallback CSV (sem picker) já pode vir com @ — mantém.
            if (is_array($components)) {
                $cver = is_array($_POST['cver'] ?? null) ? $_POST['cver'] : [];
                $components = array_map(static function ($slug) use ($cver) {
                    $slug = (string) $slug;
                    $v = $cver[$slug] ?? null;
                    return ComponentRef::compose(
                        $slug,
                        is_numeric($v) ? (int) $v : null
                    );
                }, $components);
            }
            $slugsInput = Combos::parseComponentsInput(
                is_array($components) ? $components : (string) $components
            );
            // O combo deve respeitar as regras de categoria e de aplicações
            // independentes. A categoria vem do catálogo.
            [$catRules] = self::catalog();
            $slugCat = [];
            foreach ($catRules as $cr) {
                $cs = trim((string) ($cr['slug'] ?? ''));
                if ($cs !== '') {
                    $slugCat[$cs] = trim((string) ($cr['category'] ?? ''));
                }
            }
            $ruleItems = array_map(static function ($tok) use ($slugCat) {
                $sl = ComponentRef::slug((string) $tok);
                return ['slug' => $sl, 'category' => $slugCat[$sl] ?? ''];
            }, $slugsInput);
            $chk = SelectionRules::check($ruleItems);
            if (!$chk['ok']) {
                return self::shell(self::card(
                    self::header('Combo inválido', 'Regras de seleção '
                        . '(as mesmas regras usadas na configuração do catálogo).'),
                    '<ul>' . implode('', array_map(
                        static fn ($x) => '<li>' . self::e($x) . '</li>',
                        $chk['errors']
                    )) . '</ul><a class="btn btn-default" href="'
                    . self::e($base) . '">' . self::ic('arrow-left')
                    . 'Voltar</a>'
                ));
            }
            $res = Combos::save(
                (int) ($_POST['id'] ?? 0) ?: null,
                (string) ($_POST['name'] ?? ''),
                $slugsInput
            );
            if (!$res['ok']) {
                return self::shell(self::card(
                    self::header('Erro ao salvar', ''),
                    '<ul>' . implode('', array_map(
                        static fn ($x) => '<li>' . self::e($x) . '</li>',
                        $res['errors']
                    )) . '</ul><a class="btn btn-default" href="'
                    . self::e($base) . '">Voltar</a>'
                ));
            }
            $to = $base . '&action=combos'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&saved=1';
            return self::redirect($to);
        } elseif ($action === 'delete') {
            Combos::delete((int) ($_POST['id'] ?? 0));
            $to = $base . '&action=combos'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&deleted=1';
            return self::redirect($to);
        } elseif ($action === 'assign') {
            $cid = (int) ($_POST['combo_id'] ?? 0);
            Combos::assignProduct(
                (int) ($_POST['pid'] ?? 0),
                $cid > 0 ? $cid : null
            );
            $to = $base . '&action=combos'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&assigned=1';
            return self::redirect($to);
        } elseif ($action === 'pricing-apply') {
            self::applyPricing($pid);
            $to = $base . '&action=pricing&pid=' . $pid . '&done=1';
            return self::redirect($to);
        } elseif ($action === 'prices-sync') {
            // Sincronização manual: apenas os produtos recebidos no POST,
            // seja uma linha ou a seleção marcada na tela. Reutiliza
            // applyPricing: considera só ciclos do plano, nunca zera e não
            // altera a taxa de configuração de uma linha existente.
            $raw = $_POST['pids'] ?? [];
            $ids = [];
            foreach (is_array($raw) ? $raw : [$raw] as $v) {
                $n = (int) $v;
                if ($n > 0 && !in_array($n, $ids, true)) {
                    $ids[] = $n;
                }
            }
            foreach ($ids as $n) {
                self::applyPricing($n);
            }
            $to = $base . '&action=prices&synced=' . count($ids);
            return self::redirect($to);
        } elseif ($action === 'orderform-apply') {
            [$catalog, ] = self::catalog();
            ConfigurableOptions::apply(
                $catalog,
                Combos::all(),
                $pid > 0 ? $pid : null,
                self::locations()
            );
            $to = $base . '&action=orderform'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&done=1';
            return self::redirect($to);
        } elseif ($action === 'orderform-remove') {
            ConfigurableOptions::remove();
            $to = $base . '&action=orderform'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&removed=1';
            return self::redirect($to);
        } elseif ($action === 'options-apply') {
            [$groups, ] = self::optionGroups($pid);
            $linkRaw = $_POST['link'] ?? [];
            $link = [];
            foreach (is_array($linkRaw) ? $linkRaw : [$linkRaw] as $u) {
                $u = trim((string) $u);
                if ($u !== '' && !in_array($u, $link, true)) {
                    $link[] = $u;
                }
            }
            OptionGroups::apply($groups, $link, $pid > 0 ? $pid : null);
            $to = $base . '&action=options'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&done=1';
            return self::redirect($to);
        } elseif ($action === 'options-remove') {
            OptionGroups::remove();
            $to = $base . '&action=options'
                . ($pid > 0 ? '&pid=' . $pid : '') . '&removed=1';
            return self::redirect($to);
        } elseif ($action === 'webhook-connect') {
            $res = WebhookConfig::connect((string) ($_POST['url'] ?? ''));
            return self::shell(self::webhooksView(
                $base,
                $res['ok'] ? '' : $res['error'],
                $res['ok'] ? 'Webhooks conectados com segurança.' : ''
            ));
        } elseif ($action === 'webhook-rotate') {
            $res = WebhookConfig::rotate();
            return self::shell(self::webhooksView(
                $base,
                $res['ok'] ? '' : $res['error'],
                $res['ok'] ? 'Segredo rotacionado com segurança.' : ''
            ));
        } elseif ($action === 'webhook-replay') {
            $res = WebhookDiagnostics::replay(
                (string) ($_POST['delivery_uuid'] ?? '')
            );
            $to = $base . '&action=webhooks&replayed='
                . ($res['ok'] ? '1' : '0');
            if (!$res['ok']) {
                $to .= '&replay_error=' . rawurlencode($res['error']);
            }
            return self::redirect($to);
        } elseif ($action === 'setup-test') {
            return self::handleSetupTest($base);
        } elseif ($action === 'setup-save') {
            return self::handleSetupSave($base);
        } elseif ($action === 'product-create') {
            $r = Provisioning::createStarterProduct();
            if (!$r['ok']) {
                return self::shell(self::setupView(
                    $base,
                    'Não foi possível criar o produto de teste ('
                    . (string) ($r['error'] ?? '') . '). Crie manualmente em '
                    . 'Setup → Products/Services (módulo bladecp, tipo Stack).'
                ));
            }
            return self::redirect(
                $base . '&action=setup&product=' . $r['mode'] . '&pid=' . $r['pid']
            );
        } elseif ($action === 'plan-import') {
            $planId = trim((string) ($_POST['plan'] ?? ''));
            $n = 0;
            foreach (PlanSync::plans() as $p) {
                if ((string) $p['id'] === $planId) {
                    $r = PlanSync::importPlan($p, Combos::importComboId());
                    $n = ($r['ok'] && $r['mode'] === 'created') ? 1 : 0;
                    break;
                }
            }
            return self::redirect($base . '&action=planos&imported=' . $n);
        } elseif ($action === 'plans-import-all') {
            $sum = PlanSync::importMissing(Combos::importComboId());
            return self::redirect(
                $base . '&action=planos&imported=' . $sum['created']
                    . '&blocked=' . ($sum['skipped'] ?? 0)
            );
        } elseif ($action === 'plans-reorder') {
            return self::redirect($base . '&action=planos&reordered=' . PlanSync::reorder());
        } elseif ($action === 'catalog-reconcile') {
            // O wrapper resolve o contexto administrativo e o server group
            // correspondente. A tela nunca pode reconciliar o catálogo de
            // outro servidor por uma chamada global direta.
            $result = PlanSync::reconcileAvailability();
            return self::redirect($base . '&action=planos&reconciled='
                . (!empty($result['ok']) ? '1' : '0')
                . '&processed=' . (int) ($result['processed'] ?? 0)
                . '&blocked_now=' . (int) ($result['blocked'] ?? 0)
                . '&hidden=' . (int) ($result['hidden'] ?? 0));
        } elseif ($action === 'catalog-mode') {
            $mode = (string) ($_POST['catalog_mode'] ?? 'observe');
            $saved = false;
            if (class_exists(CatalogAvailabilitySync::class)) {
                $saved = CatalogAvailabilitySync::setMode($mode);
                $mode = CatalogAvailabilitySync::mode();
            } else {
                $mode = 'observe';
            }
            return self::redirect($base . '&action=planos&catalog_mode_saved='
                . ($saved ? '1' : '0') . '&catalog_mode='
                . rawurlencode($mode));
        } elseif ($action === 'plans-link') {
            $r = PlanSync::linkComponents();
            $to = $base . '&action=planos'
                . ($r['reason'] === 'no-group' ? '&nolinkgroup=1' : '&linked=' . $r['linked']);
            return self::redirect($to);
        } elseif ($action === 'plans-set-mode') {
            $m = (int) ($_POST['import_mode'] ?? 0);
            $combo = $m > 0 ? $m : null;
            Combos::setImportComboId($combo);
            $r = PlanSync::applyMode($combo); // aplica a TODOS os produtos já existentes
            return self::redirect($base . '&action=planos&modeset=' . ($r['ok'] ? $r['affected'] : 0));
        }
        $to = $base . ($pid > 0 ? '&pid=' . $pid : '');
        return self::redirect($to);
    }

    /**
     * Reabre a decisão de venda apenas depois da confirmação explícita e da
     * verificação de elegibilidade fresca do repositório de catálogo.
     */
    private static function approveCatalogSale(int $productId): bool
    {
        if ($productId <= 0 || !class_exists(\WHMCS\Database\Capsule::class)) {
            return false;
        }
        try {
            $product = \WHMCS\Database\Capsule::table('tblproducts')
                ->select(['id', 'servertype'])
                ->where('id', $productId)
                ->first();
            if ($product === null || (string) ($product->servertype ?? '') !== 'bladecp') {
                return false;
            }
            $state = CatalogStateRepository::approveFreshEligibleSale($productId);
            if ($state === null || empty($state['effectiveSalesEligible'])) {
                return false;
            }
            // Nunca desfaz uma ocultação manual. Só a ocultação marcada pelo
            // próprio módulo pode ser revertida por esta confirmação.
            if (!empty($state['hiddenByBladecp'])) {
                \WHMCS\Database\Capsule::table('tblproducts')
                    ->where('id', $productId)
                    ->update(['hidden' => 0]);
                CatalogStateRepository::markVisibleByModule($productId);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ── Helpers ──

    /** @return array{0:array,1:bool} catálogo + ok */
    private static function catalog(): array
    {
        try {
            if (!class_exists(ServerConfig::class)) {
                return [[], false];
            }
            $client = ServerConfig::client();
            if ($client === null) {
                return [[], false];
            }
            $rows = Catalog::catalog($client);
            return [$rows ?? [], $rows !== null];
        } catch (\Throwable) {
            return [[], false];
        }
    }

    /** Locations do partner (id => label) p/ a Configurable Option
     *  Location. Fail-soft `[]` (offline/sem Server record). */
    private static function locations(): array
    {
        try {
            if (!class_exists(\WHMCS\Module\Server\Bladecp\Config\Loaders::class)) {
                return [];
            }
            return \WHMCS\Module\Server\Bladecp\Config\Loaders::locations();
        } catch (\Throwable) {
            return [];
        }
    }

    private static function productName(int $pid): string
    {
        try {
            if (!class_exists(\WHMCS\Database\Capsule::class)) {
                return 'Produto #' . $pid;
            }
            $r = \WHMCS\Database\Capsule::table('tblproducts')
                ->where('id', $pid)->first();
            $n = $r->name ?? '';
            return $n !== '' ? (string) $n : 'Produto #' . $pid;
        } catch (\Throwable) {
            return 'Produto #' . $pid;
        }
    }

    private static function shell(string $inner): string
    {
        return Layout::shell($inner);
    }

    private static function card(string $head, string $body): string
    {
        return Layout::card($head, $body);
    }

    private static function header(
        string $title,
        string $sub,
        bool $showLogo = true
    ): string
    {
        return Layout::header($title, $sub, $showLogo);
    }

    /**
     * Navegação do addon. Server-rendered, por links com `action`, sem SPA.
     * As telas de produto preservam `pid`; acompanhamento e diagnóstico são
     * globais e sempre somente leitura.
     */
    private static function tabs(string $base, string $active): string
    {
        return Layout::tabs($base, $active);
    }

    /** Ícone Lucide inline (currentColor → herda a cor do botão). */
    private static function ic(string $name): string
    {
        return Layout::icon($name);
    }

    public static function postBtn(
        string $base,
        string $action,
        array $fields,
        string $label,
        string $confirm,
        string $icon = 'trash'
    ): string {
        $hidden = '<input type="hidden" name="action" value="'
            . self::e($action) . '">';
        foreach ($fields as $k => $v) {
            $hidden .= '<input type="hidden" name="' . self::e($k)
                . '" value="' . self::e($v) . '">';
        }
        return '<form method="post" action="' . self::e($base)
            . '" style="display:inline" onsubmit="return confirm('
            . htmlspecialchars(
                json_encode($confirm, JSON_THROW_ON_ERROR),
                ENT_QUOTES,
                'UTF-8'
            ) . ')">' . self::token() . $hidden
            . '<button class="btn btn-danger" type="submit">'
            . self::ic($icon) . self::e($label) . '</button></form>';
    }

    private static function token(): string
    {
        return Layout::token();
    }

    private static function checkToken(): bool
    {
        if (!function_exists('check_token')) {
            return false;
        }
        return Layout::checkToken();
    }

    private static function e(mixed $s): string
    {
        return Layout::escape($s);
    }

    private static function docsLink(string $label, string $class = ''): string
    {
        return Layout::docsLink($label, $class);
    }

    /** CSS específico do addon (escopado; reusa tokens do Assets). */
    private static function css(): string
    {
        return '';
    }
}
