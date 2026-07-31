<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'marketplace.title' => 'Marketplace',
    'marketplace.subtitle' => 'Vendas faturadas pela BladeCP e atribuídas a você pelo canal marketplace, com o seu repasse e os extratos. Somente leitura, não altera o billing do WHMCS. Valores por moeda.',
    'marketplace.read_only_note' => 'Visão somente leitura da BladeCP com a sua API key. Não cria nem edita faturas, pedidos ou preços no WHMCS. Valores por moeda, e moedas diferentes nunca são somadas. O pagamento do repasse e o extrato oficial acontecem na BladeCP.',
    'marketplace.sold_orders' => 'Pedidos vendidos',
    'marketplace.orders_error' => 'Não foi possível carregar os pedidos do marketplace. Verifique o Server record (API key bcp_* e Test Connection OK) e se o canal marketplace está ativo na sua conta.',
    'marketplace.orders_empty' => 'Nenhuma venda atribuída a você ainda. Quando a BladeCP vender um plano seu listado no marketplace, o pedido aparece aqui.',
    'marketplace.customer' => 'Cliente', 'marketplace.product_plan' => 'Produto / plano', 'marketplace.retail' => 'Varejo', 'marketplace.commission' => 'Comissão', 'marketplace.net' => 'Líquido', 'marketplace.status' => 'Status', 'marketplace.sold_at' => 'Vendido em',
    'marketplace.orders_count' => '{count} pedido(s)', 'marketplace.bladecp_commission' => 'Comissão BladeCP', 'marketplace.your_net' => 'Seu líquido',
    'marketplace.page_summary' => 'Resumo desta página, agregado por moeda. Valores de moedas diferentes nunca são somados. O líquido é pré-retenção fiscal, e o extrato oficial de repasse fica na plataforma BladeCP.',
    'marketplace.previous' => 'Anterior', 'marketplace.next' => 'Próxima', 'marketplace.page_of' => 'Página {page} de {pages} · {total} pedido(s)',
    'marketplace.open_substrate' => 'Mensalidade em aberto (substrato)', 'marketplace.substrate_error' => 'Não foi possível carregar as faturas de substrato.', 'marketplace.invoices_count' => '{count} fatura(s)', 'marketplace.total_outstanding' => 'Total em aberto', 'marketplace.substrate_empty' => 'Nenhuma fatura de mensalidade em aberto.',
    'marketplace.invoice' => 'Fatura', 'marketplace.amount' => 'Valor', 'marketplace.due_date' => 'Vencimento',
    'marketplace.payout_commission' => 'Repasse / comissão', 'marketplace.payout_error' => 'Não foi possível carregar o resumo de repasse.', 'marketplace.payout_empty' => 'Sem repasse registrado ainda.', 'marketplace.pending' => 'Pendente', 'marketplace.confirmed' => 'Confirmado', 'marketplace.cleared' => 'Liberado', 'marketplace.lifetime_net' => 'Líquido (vida)',
    'marketplace.substrate_coverage' => 'Cobertura do substrato', 'marketplace.coverage_error' => 'Não foi possível carregar a cobertura.', 'marketplace.coverage_empty' => 'Nenhum servidor-base ativo foi associado à cobertura deste canal. O opt-in e a escolha do servidor-base são feitos no painel BladeCP, e esta tela é somente leitura.', 'marketplace.monthly' => 'Mensalidade', 'marketplace.covered' => 'Coberto', 'marketplace.remaining' => 'Falta cobrir', 'marketplace.sales_to_break_even' => 'Vendas até o break-even',
    'marketplace.catalog' => 'Catálogo', 'marketplace.catalog_error' => 'Não foi possível carregar o catálogo.', 'marketplace.active_listings' => 'Listagens ativas', 'marketplace.sold_subscriptions' => 'Assinaturas vendidas', 'marketplace.catalog_empty' => 'Nenhum plano está listado. Ative o marketplace e publique os planos pelo painel BladeCP. O addon não altera opt-in nem listagens.',
    'marketplace.statements' => 'Extratos', 'marketplace.statements_error' => 'Não foi possível carregar os extratos.', 'marketplace.statements_empty' => 'Nenhum extrato de fechamento ainda.', 'marketplace.period' => 'Período', 'marketplace.gross' => 'Bruto', 'marketplace.substrate' => 'Substrato', 'marketplace.paid_at' => 'Pago em', 'marketplace.actions' => 'Ações', 'marketplace.details' => 'Detalhes',
    'marketplace.statement_detail' => 'Detalhe do extrato #{id}', 'marketplace.close_detail' => 'Fechar detalhe', 'marketplace.statement_error' => 'Não foi possível carregar este extrato. Ele pode não existir ou não pertencer à conta conectada.',
    'marketplace.commission_ledger' => 'Ledger de comissões', 'marketplace.all_states' => 'Todos os estados', 'marketplace.filter_ledger' => 'Filtrar ledger por estado', 'marketplace.filter' => 'Filtrar', 'marketplace.ledger_error' => 'Não foi possível carregar o ledger. A API key precisa do escopo partner:read.', 'marketplace.ledger_empty' => 'Nenhuma linha de comissão neste filtro.', 'marketplace.statement_lines_empty' => 'Este extrato não possui linhas.', 'marketplace.not_provided' => 'Não informado', 'marketplace.reference' => 'Referência', 'marketplace.source' => 'Origem', 'marketplace.accrued_at' => 'Acumulado em', 'marketplace.invoice_prefix' => 'Fatura {id}', 'marketplace.lines_page_of' => 'Página {page} de {pages} · {total} linha(s)',
    'marketplace.status.cleared' => 'Liberado', 'marketplace.status.confirmed' => 'Confirmado', 'marketplace.status.paid' => 'Pago', 'marketplace.status.active' => 'Ativo', 'marketplace.status.completed' => 'Concluído', 'marketplace.status.pending' => 'Pendente', 'marketplace.status.open' => 'Em aberto', 'marketplace.status.processing' => 'Processando', 'marketplace.status.provisioning' => 'Provisionando', 'marketplace.status.reversed' => 'Estornado', 'marketplace.status.refunded' => 'Reembolsado', 'marketplace.status.chargeback' => 'Contestação', 'marketplace.status.cancelled' => 'Cancelado', 'marketplace.status.canceled' => 'Cancelado', 'marketplace.status.failed' => 'Falhou', 'marketplace.status.suspended' => 'Suspenso',
];
