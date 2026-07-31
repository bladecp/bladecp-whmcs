<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\Bladecp\Admin;

use WHMCS\Module\Addon\Bladecp\Marketplace;
use WHMCS\Module\Addon\Bladecp\MarketplaceAccount;
use WHMCS\Module\Addon\Bladecp\AdminPage;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** Renderização read-only da aba Marketplace. */
final class MarketplaceView
{
    public static function render(string $base): string
    {
        return self::marketplaceView($base);
    }
    public static function marketplaceView(string $base): string
    {
        $h = Layout::header(
            'Marketplace',
            'Vendas que a BladeCP faturou e atribuiu a você pelo canal '
                . 'marketplace, com o seu repasse e os extratos. Somente '
                . 'leitura — não altera o billing do WHMCS. Valores por moeda.'
        );

        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $ledgerPage = isset($_GET['ledger_page'])
            ? max(1, (int) $_GET['ledger_page']) : 1;
        $ledgerStatus = trim((string) ($_GET['ledger_status'] ?? ''));
        $statementId = isset($_GET['statement'])
            ? max(0, (int) $_GET['statement']) : 0;
        $orders = Marketplace::orders($page, Marketplace::PAGE_SIZE);
        $ledger = MarketplaceAccount::commissionLines(
            $ledgerPage,
            MarketplaceAccount::PAGE_SIZE,
            $ledgerStatus
        );
        $statement = $statementId > 0
            ? MarketplaceAccount::statement($statementId) : null;

        $acct = MarketplaceAccount::load();
        $summary = $acct['summary'];

        // Corpo (esquerda): pedidos + tabelas financeiras.
        $main = self::mktOrdersBlock($base, $orders)
            . self::acctSubstrateBlock($acct['substrate'])
            . self::acctStatementsBlock($base, $acct['statements'])
            . ($statementId > 0
                ? self::acctStatementDetailBlock($base, $statementId, $statement)
                : '')
            . self::acctCommissionLinesBlock(
                $base,
                $ledger,
                $ledgerStatus
            );

        // Sidebar (direita): repasse, cobertura/break-even e catálogo.
        $rail = self::acctCommissionBlock($summary)
            . self::acctCoverageBlock($summary)
            . self::acctCatalogCard($summary);

        $layout = '<div class="bcp-mkt-layout"><div class="bcp-mkt-main">'
            . $main . '</div><aside class="bcp-mkt-rail">' . $rail
            . '</aside></div>';

        $note = '<div class="bcp-chips-note">Visão somente leitura puxada '
            . 'da BladeCP com a sua API key — não cria nem edita faturas, '
            . 'pedidos ou preços no WHMCS. Valores por moeda; moedas '
            . 'diferentes nunca são somadas. O pagamento do repasse e o '
            . 'extrato oficial acontecem na BladeCP.</div>';

        return Layout::card($h, Layout::tabs($base, 'marketplace')
            . $layout . $note);
    }

    /**
     * Bloco "Pedidos vendidos" (corpo): resumo por moeda desta página +
     * a tabela de pedidos + pager. Fail-soft: null → aviso; vazio → nota.
     */
    public static function mktOrdersBlock(string $base, ?array $data): string
    {
        $sec = '<div class="bcp-sec">Pedidos vendidos</div>';
        if ($data === null) {
            return $sec . '<p class="bcp-row-v">Não foi possível carregar '
                . 'os pedidos do marketplace. Verifique o <strong>Server '
                . 'record</strong> (API key bcp_* + Test Connection OK) e se '
                . 'o canal marketplace está ativo na sua conta.</p>';
        }
        $items = $data['items'];
        if ($items === []) {
            return $sec . '<p class="bcp-row-v">Nenhuma venda atribuída a '
                . 'você ainda. Quando a BladeCP vender um plano seu listado '
                . 'no marketplace, o pedido aparece aqui.</p>';
        }
        $rows = '';
        foreach ($items as $o) {
            $rows .= self::marketplaceRow($o);
        }
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr>'
            . '<th scope="col">Cliente</th><th scope="col">Produto / Plano</th><th scope="col">Varejo</th>'
            . '<th scope="col">Comissão</th><th scope="col">Líquido</th><th scope="col">Status</th>'
            . '<th scope="col">Vendido em</th></tr></thead><tbody>' . $rows
            . '</tbody></table></div>';

        return $sec . self::marketplaceSummary($items) . $table
            . self::marketplacePager($base, $data);
    }

    /** 1 linha de pedido (escapada; money em centavos ÷100). */
    public static function marketplaceRow(array $o): string
    {
        $code = (string) ($o['currency'] ?? '');

        $name = ($o['customerName'] ?? '') !== ''
            ? '<strong>' . Layout::escape($o['customerName']) . '</strong>'
            : '<strong>—</strong>';
        if (($o['customerEmail'] ?? '') !== '') {
            $name .= '<div class="bcp-csub">'
                . Layout::escape($o['customerEmail']) . '</div>';
        }

        $prod = '<strong>'
            . Layout::escape(($o['productName'] ?? '') !== '' ? $o['productName'] : '—')
            . '</strong>';
        if (($o['planName'] ?? '') !== '') {
            $prod .= '<div class="bcp-row-v">' . Layout::escape($o['planName'])
                . '</div>';
        }
        if (($o['provisionUuid'] ?? '') !== '') {
            $prod .= '<div class="bcp-csub">' . Layout::escape($o['provisionUuid'])
                . '</div>';
        }

        return '<tr><td>' . $name . '</td><td>' . $prod . '</td>'
            . '<td>' . self::centsCell((int) ($o['retailCents'] ?? 0), $code)
            . '</td><td>'
            . self::centsCell((int) ($o['commissionCents'] ?? 0), $code)
            . '</td><td>'
            . self::centsCell((int) ($o['netCents'] ?? 0), $code)
            . '</td><td>'
            . self::marketplaceStatusBadge((string) ($o['status'] ?? ''))
            . '</td><td class="bcp-row-v">'
            . (($o['soldAtLabel'] ?? '') !== ''
                ? Layout::escape($o['soldAtLabel'])
                : '—')
            . '</td></tr>';
    }

    /** Célula de valor em centavos → moeda; sem moeda → "—". */
    public static function centsCell(int $cents, string $code): string
    {
        if ($code === '') {
            return '<span class="bcp-row-v muted">—</span>';
        }
        return '<span class="bcp-row-v">'
            . self::money($cents / 100, $code) . '</span>';
    }

    /** Formata um valor monetário já convertido de centavos. */
    private static function money(float $value, string $code): string
    {
        return Layout::escape(
            strtoupper(trim($code))
                . ' '
                . number_format($value, 2, ',', '.')
        );
    }

    /** Badge de status com cor por bucket; desconhecido → pill neutro. */
    public static function marketplaceStatusBadge(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s === '') {
            return '<span class="bcp-row-v muted">—</span>';
        }
        $ok = ['cleared', 'confirmed', 'paid', 'active', 'completed'];
        $warn = ['pending', 'open', 'processing', 'provisioning'];
        $err = ['reversed', 'refunded', 'chargeback', 'cancelled',
            'canceled', 'failed', 'suspended'];
        if (in_array($s, $ok, true)) {
            $cls = 'is-ok';
        } elseif (in_array($s, $warn, true)) {
            $cls = 'is-warn';
        } elseif (in_array($s, $err, true)) {
            $cls = 'is-err';
        } else {
            return '<span class="bcp-pill">' . Layout::escape($status) . '</span>';
        }
        return '<span class="bcp-badge ' . $cls . '">'
            . Layout::escape($status) . '</span>';
    }

    /**
     * Resumo agregado por MOEDA desta página (contagem + varejo +
     * comissão + líquido). NUNCA soma moedas diferentes — cada moeda
     * é um card. Vazio (nenhuma linha com moeda) → "".
     *
     * @param array<int,array<string,mixed>> $items
     */
    public static function marketplaceSummary(array $items): string
    {
        $byCur = Marketplace::summarize($items);
        if ($byCur === []) {
            return '';
        }
        $cards = '';
        foreach ($byCur as $code => $t) {
            $cards .= '<div class="bcp-mkt-sum bcp-mkt-sum-accent"><div class="bcp-mkt-sum-h">'
                . Layout::escape($code) . ' <span class="bcp-csub">'
                . (int) $t['count'] . ' pedido(s)</span></div>'
                . '<div class="bcp-mkt-sum-r"><span>Varejo</span><strong>'
                . self::money(((int) $t['retailCents']) / 100, $code)
                . '</strong></div>'
                . '<div class="bcp-mkt-sum-r"><span>Comissão BladeCP</span>'
                . '<strong>'
                . self::money(((int) $t['commissionCents']) / 100, $code)
                . '</strong></div>'
                . '<div class="bcp-mkt-sum-r"><span>Seu líquido</span>'
                . '<strong>'
                . self::money(((int) $t['netCents']) / 100, $code)
                . '</strong></div></div>';
        }
        return '<div class="bcp-mkt-sums">' . $cards . '</div>'
            . '<p class="bcp-chips-note">Resumo desta página, agregado '
            . 'por moeda — valores de moedas diferentes nunca são '
            . 'somados. O líquido é pré-retenção fiscal; o extrato '
            . 'oficial de repasse fica na plataforma BladeCP.</p>';
    }

    /** Paginação prev/próxima (server-rendered, sem pid). */
    public static function marketplacePager(string $base, array $data): string
    {
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = (int) ($data['pageSize'] ?? 0);
        if ($pageSize <= 0) {
            $pageSize = Marketplace::PAGE_SIZE;
        }
        $total = max(0, (int) ($data['total'] ?? 0));
        $pages = $total > 0 ? (int) ceil($total / $pageSize) : 1;
        if ($pages <= 1) {
            return '';
        }
        $link = static function (int $p, string $label, string $icon) use ($base): string {
            return '<a class="btn btn-default" href="' . Layout::escape(
                $base . '&action=marketplace&page=' . $p
            ) . '">' . ($icon !== '' ? Layout::icon($icon) : '')
                . Layout::escape($label) . '</a>';
        };
        $prev = $page > 1 ? $link($page - 1, 'Anterior', 'arrow-left') : '';
        $next = $page < $pages ? $link($page + 1, 'Próxima', '') : '';
        return '<div class="bcp-actions bcp-mkt-pager">'
            . '<span class="bcp-row-v">Página ' . $page . ' de ' . $pages
            . ' · ' . $total . ' pedido(s)</span>' . $prev . $next
            . '</div>';
    }

    // "Minha conta" foi unificada na aba Marketplace (marketplaceView):
    // pedidos + tabelas financeiras no corpo; repasse, cobertura e
    // catálogo no sidebar. Os acct*Block abaixo são reusados de lá.
    // O ?action=account continua funcionando como alias legado.

    /** Mensalidade do substrato em aberto (BRL) + lista de faturas. */
    public static function acctSubstrateBlock(?array $substrate): string
    {
        $sec = '<div class="bcp-sec">Mensalidade em aberto (substrato)</div>';
        if ($substrate === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar as faturas de substrato.</p>';
        }
        $code = $substrate['currency'] ?: 'BRL';
        $total = '<div class="bcp-mkt-sum bcp-mkt-sum-accent"><div class="bcp-mkt-sum-h">'
            . Layout::escape($code) . ' <span class="bcp-csub">'
            . count($substrate['items']) . ' fatura(s)</span></div>'
            . '<div class="bcp-mkt-sum-r"><span>Total em aberto</span>'
            . '<strong>' . self::money($substrate['totalCents'] / 100, $code)
            . '</strong></div></div>';

        if ($substrate['items'] === []) {
            return $sec . '<div class="bcp-mkt-sums">' . $total . '</div>'
                . '<p class="bcp-row-v muted">Nenhuma fatura de mensalidade '
                . 'em aberto.</p>';
        }

        $rows = '';
        foreach ($substrate['items'] as $it) {
            $num = ($it['invoiceNumber'] ?? '') !== ''
                ? $it['invoiceNumber']
                : ($it['invoiceId'] ?? '');
            $rows .= '<tr><td><strong>' . Layout::escape($num) . '</strong>'
                . (($it['subscriptionLabel'] ?? '') !== ''
                    ? '<div class="bcp-csub">'
                        . Layout::escape($it['subscriptionLabel']) . '</div>'
                    : '')
                . '</td><td>'
                . self::centsCell((int) ($it['totalCents'] ?? 0), $code)
                . '</td><td>'
                . self::marketplaceStatusBadge((string) ($it['status'] ?? ''))
                . '</td><td class="bcp-row-v">'
                . (($it['dueDate'] ?? '') !== '' ? Layout::escape($it['dueDate']) : '—')
                . '</td></tr>';
        }
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Fatura</th>'
            . '<th scope="col">Valor</th><th scope="col">Status</th><th scope="col">Vencimento</th></tr>'
            . '</thead><tbody>' . $rows . '</tbody></table></div>';

        return $sec . '<div class="bcp-mkt-sums">' . $total . '</div>'
            . $table;
    }

    /** Repasse/comissão por moeda (pendente/confirmado/liberado/líquido). */
    public static function acctCommissionBlock(?array $summary): string
    {
        $sec = '<div class="bcp-sec">Repasse / comissão</div>';
        if ($summary === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar o resumo de repasse.</p>';
        }
        $curs = $summary['currencies'];
        if ($curs === []) {
            return $sec . '<p class="bcp-row-v muted">Sem repasse '
                . 'registrado ainda.</p>';
        }
        $cards = '';
        foreach ($curs as $c) {
            $code = (string) ($c['currency'] ?? '');
            $cards .= '<div class="bcp-mkt-sum bcp-mkt-sum-accent"><div class="bcp-mkt-sum-h">'
                . Layout::escape($code) . '</div>'
                . '<div class="bcp-mkt-sum-r"><span>Pendente</span><strong>'
                . self::money(((int) $c['pendingCents']) / 100, $code)
                . '</strong></div>'
                . '<div class="bcp-mkt-sum-r"><span>Confirmado</span><strong>'
                . self::money(((int) $c['confirmedCents']) / 100, $code)
                . '</strong></div>'
                . '<div class="bcp-mkt-sum-r"><span>Liberado</span><strong>'
                . self::money(((int) $c['clearedCents']) / 100, $code)
                . '</strong></div>'
                . '<div class="bcp-mkt-sum-r"><span>Líquido (vida)</span>'
                . '<strong>'
                . self::money(((int) $c['lifetimeNetPayableCents']) / 100, $code)
                . '</strong></div></div>';
        }
        return $sec . '<div class="bcp-mkt-sums">' . $cards . '</div>';
    }

    /** Cobertura do substrato + break-even (BRL). */
    public static function acctCoverageBlock(?array $summary): string
    {
        $sec = '<div class="bcp-sec">Cobertura do substrato</div>';
        if ($summary === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar a cobertura.</p>';
        }
        $cov = $summary['substrateCoverage'];
        if ($cov === null) {
            return $sec . '<p class="bcp-row-v muted">Nenhum servidor-base '
                . 'ativo foi associado à cobertura deste canal. O opt-in e '
                . 'a escolha do servidor-base são feitos no painel BladeCP; '
                . 'esta tela '
                . 'é somente leitura.</p>';
        }
        $code = 'BRL';
        $break = $cov['breakEvenSalesRemaining'];
        $monthly = (int) $cov['substrateMonthlyCents'];
        $covered = (int) $cov['coveredCents'];
        // Barra de progresso até o break-even (coberto / mensalidade).
        $pct = $monthly > 0
            ? max(0, min(100, (int) round($covered * 100 / $monthly)))
            : 0;
        $card = '<div class="bcp-mkt-sum"><div class="bcp-mkt-sum-h">'
            . Layout::escape($code) . '</div>'
            . '<div class="bcp-mkt-sum-r"><span>Mensalidade</span><strong>'
            . self::money($monthly / 100, $code)
            . '</strong></div>'
            . '<div class="bcp-mkt-sum-r"><span>Coberto</span><strong>'
            . self::money($covered / 100, $code)
            . '</strong></div>'
            . '<div class="bcp-mkt-bar"><i style="width:' . $pct . '%"></i></div>'
            . '<div class="bcp-mkt-sum-r"><span>Falta cobrir</span><strong>'
            . self::money(((int) $cov['remainingCents']) / 100, $code)
            . '</strong></div>'
            . '<div class="bcp-mkt-sum-r"><span>Vendas até o break-even</span>'
            . '<strong>' . ($break !== null ? (int) $break : '—')
            . '</strong></div></div>';

        return $sec . '<div class="bcp-mkt-sums">' . $card . '</div>';
    }

    /**
     * Contadores do catálogo (listagens ativas + assinaturas vendidas).
     * Card próprio — antes vivia dentro da cobertura e sumia quando não
     * havia substrato ativo.
     */
    public static function acctCatalogCard(?array $summary): string
    {
        $sec = '<div class="bcp-sec">Catálogo</div>';
        if ($summary === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar o catálogo.</p>';
        }
        return $sec . '<div class="bcp-mkt-sums"><div class="bcp-mkt-sum">'
            . '<div class="bcp-mkt-sum-r"><span>Listagens ativas</span>'
            . '<strong>' . (int) ($summary['activeListings'] ?? 0)
            . '</strong></div>'
            . '<div class="bcp-mkt-sum-r"><span>Assinaturas vendidas</span>'
            . '<strong>' . (int) ($summary['soldSubscriptions'] ?? 0)
            . '</strong></div></div></div>'
            . ((int) ($summary['activeListings'] ?? 0) === 0
                ? '<p class="bcp-row-v muted">Nenhum plano está listado. '
                    . 'Ative o marketplace e publique os planos pelo painel '
                    . 'BladeCP. '
                    . 'O addon não altera opt-in nem listagens.</p>'
                : '');
    }

    /** Extratos de fechamento (direção/status/NET). */
    public static function acctStatementsBlock(
        string $base,
        ?array $statements
    ): string
    {
        $sec = '<div class="bcp-sec">Extratos</div>';
        if ($statements === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar os extratos.</p>';
        }
        if ($statements['items'] === []) {
            return $sec . '<p class="bcp-row-v muted">Nenhum extrato de '
                . 'fechamento ainda.</p>';
        }
        $rows = '';
        foreach ($statements['items'] as $s) {
            $code = (string) ($s['currency'] ?? '');
            $period = trim(
                (string) ($s['periodStart'] ?? '') . ' – '
                . (string) ($s['periodEnd'] ?? ''),
                ' –'
            );
            $rows .= '<tr><td><strong>' . Layout::escape($period !== '' ? $period : '—')
                . '</strong>'
                . (($s['direction'] ?? '') !== ''
                    ? '<div class="bcp-csub">' . Layout::escape($s['direction'])
                        . '</div>'
                    : '')
                . '</td><td>'
                . self::centsCell((int) ($s['totalGrossCents'] ?? 0), $code)
                . '</td><td>'
                . self::centsCell((int) ($s['totalCommissionCents'] ?? 0), $code)
                . '</td><td>'
                . self::centsCell((int) ($s['substrateInvoiceCents'] ?? 0), $code)
                . '</td><td>'
                . self::centsCell((int) ($s['settlementNetCents'] ?? 0), $code)
                . '</td><td>'
                . self::marketplaceStatusBadge((string) ($s['status'] ?? ''))
                . '</td><td class="bcp-row-v">'
                . (($s['paidAtLabel'] ?? '') !== ''
                    ? Layout::escape($s['paidAtLabel'])
                    : '—')
                . '</td><td><a class="btn btn-default" href="'
                . Layout::escape($base . '&action=marketplace&statement=' . (int) $s['id'])
                . '">Detalhes</a></td></tr>';
        }
        $table = '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Período</th>'
            . '<th scope="col">Bruto</th><th scope="col">Comissão</th><th scope="col">Substrato</th>'
            . '<th scope="col">NET</th><th scope="col">Status</th><th scope="col">Pago em</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
        return $sec . $table;
    }

    /**
     * Extrato selecionado e suas linhas. A API garante ownership; null
     * representa 404, indisponibilidade ou shape inesperado sem quebrar a aba.
     */
    public static function acctStatementDetailBlock(
        string $base,
        int $statementId,
        ?array $detail
    ): string {
        $sec = '<div class="bcp-sec">Detalhe do extrato #'
            . $statementId . '</div>';
        $back = '<p><a class="btn btn-default" href="'
            . Layout::escape($base . '&action=marketplace')
            . '">Fechar detalhe</a></p>';
        if ($detail === null) {
            return $sec . '<p class="bcp-row-v muted">Não foi possível '
                . 'carregar este extrato. Ele pode não existir ou não '
                . 'pertencer à conta conectada.</p>' . $back;
        }
        $statement = $detail['statement'];
        $code = (string) ($statement['currency'] ?? '');
        $summary = '<div class="bcp-mkt-sums"><div class="bcp-mkt-sum">'
            . '<div class="bcp-mkt-sum-h">' . Layout::escape($code) . '</div>'
            . '<div class="bcp-mkt-sum-r"><span>Bruto</span><strong>'
            . self::money(((int) ($statement['totalGrossCents'] ?? 0)) / 100, $code)
            . '</strong></div>'
            . '<div class="bcp-mkt-sum-r"><span>Comissão</span><strong>'
            . self::money(
                ((int) ($statement['totalCommissionCents'] ?? 0)) / 100,
                $code
            )
            . '</strong></div>'
            . '<div class="bcp-mkt-sum-r"><span>NET</span><strong>'
            . self::money(
                ((int) ($statement['settlementNetCents'] ?? 0)) / 100,
                $code
            )
            . '</strong></div></div></div>';
        return $sec . $summary
            . self::commissionLinesTable($detail['lines'])
            . $back;
    }

    /** Ledger paginado, somente leitura, com filtro de estado. */
    public static function acctCommissionLinesBlock(
        string $base,
        ?array $ledger,
        string $selectedStatus
    ): string {
        $sec = '<div class="bcp-sec">Ledger de comissões</div>';
        $options = '<option value="">Todos os estados</option>';
        foreach (MarketplaceAccount::COMMISSION_STATUSES as $status) {
            $options .= '<option value="' . Layout::escape($status) . '"'
                . ($selectedStatus === $status ? ' selected' : '') . '>'
                . Layout::escape($status) . '</option>';
        }
        $filter = '<form method="get" action="' . Layout::escape($base)
            . '" class="bcp-actions">'
            . '<input type="hidden" name="module" value="bladecp">'
            . '<input type="hidden" name="action" value="marketplace">'
            . '<label class="sr-only" for="bcp-ledger-status">Filtrar ledger por estado</label>'
            . '<select id="bcp-ledger-status" name="ledger_status" class="form-control" '
            . 'style="max-width:220px">' . $options . '</select>'
            . '<button type="submit" class="btn btn-default">Filtrar</button>'
            . '</form>';
        if ($ledger === null) {
            return $sec . $filter . '<p class="bcp-row-v muted">Não foi '
                . 'possível carregar o ledger. A API key precisa do escopo '
                . '<code>partner:read</code>.</p>';
        }
        if ($ledger['items'] === []) {
            return $sec . $filter . '<p class="bcp-row-v muted">Nenhuma '
                . 'linha de comissão neste filtro.</p>';
        }
        return $sec . $filter
            . self::commissionLinesTable($ledger['items'])
            . self::commissionLinesPager($base, $ledger, $selectedStatus);
    }

    /** @param list<array<string,mixed>> $lines */
    public static function commissionLinesTable(array $lines): string
    {
        if ($lines === []) {
            return '<p class="bcp-row-v muted">Este extrato não possui linhas.</p>';
        }
        $rows = '';
        foreach ($lines as $line) {
            $code = (string) ($line['currency'] ?? '');
            $reference = ($line['productName'] ?? '') !== ''
                ? '<strong>' . Layout::escape($line['productName']) . '</strong>'
                : 'Não informado';
            if (($line['invoiceId'] ?? '') !== '') {
                $reference .= '<div class="bcp-csub">Fatura '
                    . Layout::escape($line['invoiceId']) . '</div>';
            }
            $rows .= '<tr><td>' . $reference . '</td><td>'
                . Layout::escape((string) ($line['source'] ?? '')) . '</td><td>'
                . self::centsCell((int) ($line['grossCents'] ?? 0), $code)
                . '</td><td>'
                . self::centsCell((int) ($line['commissionCents'] ?? 0), $code)
                . '</td><td>'
                . self::centsCell((int) ($line['netPayableCents'] ?? 0), $code)
                . '</td><td>'
                . self::marketplaceStatusBadge((string) ($line['status'] ?? ''))
                . '</td><td class="bcp-row-v">'
                . Layout::escape((string) (
                    $line['accruedAtLabel'] ?? 'Não informado'
                ))
                . '</td></tr>';
        }
        return '<div class="bcp-tbl-wrap"><table class="bcp-tbl"><thead><tr><th scope="col">Referência</th>'
            . '<th scope="col">Origem</th><th scope="col">Bruto</th><th scope="col">Comissão</th>'
            . '<th scope="col">Líquido</th><th scope="col">Status</th><th scope="col">Acumulado em</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }

    public static function commissionLinesPager(
        string $base,
        array $data,
        string $status
    ): string {
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = max(1, (int) ($data['pageSize'] ?? MarketplaceAccount::PAGE_SIZE));
        $total = max(0, (int) ($data['total'] ?? 0));
        $pages = max(1, (int) ceil($total / $pageSize));
        if ($pages <= 1) {
            return '';
        }
        $url = static function (int $target) use ($base, $status): string {
            return $base . '&action=marketplace&ledger_page=' . $target
                . ($status !== '' ? '&ledger_status=' . rawurlencode($status) : '');
        };
        return '<div class="bcp-actions bcp-mkt-pager">'
            . '<span class="bcp-row-v">Página ' . $page . ' de ' . $pages
            . ' · ' . $total . ' linha(s)</span>'
            . ($page > 1 ? '<a class="btn btn-default" href="'
                . Layout::escape($url($page - 1)) . '">Anterior</a>' : '')
            . ($page < $pages ? '<a class="btn btn-default" href="'
                . Layout::escape($url($page + 1)) . '">Próxima</a>' : '')
            . '</div>';
    }
}
