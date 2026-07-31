<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'marketplace.title' => 'Marketplace',
    'marketplace.subtitle' => 'Sales billed by BladeCP and attributed to you through the marketplace channel, including your payout and statements. Read-only, it does not change WHMCS billing. Amounts are shown per currency.',
    'marketplace.read_only_note' => 'Read-only data from BladeCP through your API key. It does not create or edit invoices, orders, or prices in WHMCS. Amounts are shown per currency, and different currencies are never combined. Payout and the official statement are handled in BladeCP.',
    'marketplace.sold_orders' => 'Sold orders',
    'marketplace.orders_error' => 'Could not load marketplace orders. Check the Server record (bcp_* API key and Test Connection OK) and confirm that the marketplace channel is active for your account.',
    'marketplace.orders_empty' => 'No sales have been attributed to you yet. When BladeCP sells one of your marketplace-listed plans, the order will appear here.',
    'marketplace.customer' => 'Customer', 'marketplace.product_plan' => 'Product / plan', 'marketplace.retail' => 'Retail', 'marketplace.commission' => 'Commission', 'marketplace.net' => 'Net', 'marketplace.status' => 'Status', 'marketplace.sold_at' => 'Sold at',
    'marketplace.orders_count' => '{count} order(s)', 'marketplace.bladecp_commission' => 'BladeCP commission', 'marketplace.your_net' => 'Your net',
    'marketplace.page_summary' => 'This page summary is grouped by currency. Amounts in different currencies are never combined. Net is before tax withholding, and the official payout statement is available in BladeCP.',
    'marketplace.previous' => 'Previous', 'marketplace.next' => 'Next', 'marketplace.page_of' => 'Page {page} of {pages} · {total} order(s)',
    'marketplace.open_substrate' => 'Outstanding substrate subscription', 'marketplace.substrate_error' => 'Could not load substrate invoices.', 'marketplace.invoices_count' => '{count} invoice(s)', 'marketplace.total_outstanding' => 'Total outstanding', 'marketplace.substrate_empty' => 'No outstanding subscription invoice.',
    'marketplace.invoice' => 'Invoice', 'marketplace.amount' => 'Amount', 'marketplace.due_date' => 'Due date',
    'marketplace.payout_commission' => 'Payout / commission', 'marketplace.payout_error' => 'Could not load the payout summary.', 'marketplace.payout_empty' => 'No payout has been recorded yet.', 'marketplace.pending' => 'Pending', 'marketplace.confirmed' => 'Confirmed', 'marketplace.cleared' => 'Cleared', 'marketplace.lifetime_net' => 'Lifetime net',
    'marketplace.substrate_coverage' => 'Substrate coverage', 'marketplace.coverage_error' => 'Could not load coverage.', 'marketplace.coverage_empty' => 'No active base server is associated with this channel coverage. Opt-in and base-server selection are managed in the BladeCP panel, and this screen is read-only.', 'marketplace.monthly' => 'Monthly', 'marketplace.covered' => 'Covered', 'marketplace.remaining' => 'Remaining', 'marketplace.sales_to_break_even' => 'Sales to break even',
    'marketplace.catalog' => 'Catalog', 'marketplace.catalog_error' => 'Could not load the catalog.', 'marketplace.active_listings' => 'Active listings', 'marketplace.sold_subscriptions' => 'Sold subscriptions', 'marketplace.catalog_empty' => 'No plan is listed. Enable the marketplace and publish plans from the BladeCP panel. The addon does not change opt-in or listings.',
    'marketplace.statements' => 'Statements', 'marketplace.statements_error' => 'Could not load statements.', 'marketplace.statements_empty' => 'No closing statement yet.', 'marketplace.period' => 'Period', 'marketplace.gross' => 'Gross', 'marketplace.substrate' => 'Substrate', 'marketplace.paid_at' => 'Paid at', 'marketplace.actions' => 'Actions', 'marketplace.details' => 'Details',
    'marketplace.statement_detail' => 'Statement details #{id}', 'marketplace.close_detail' => 'Close details', 'marketplace.statement_error' => 'Could not load this statement. It may not exist or may not belong to the connected account.',
    'marketplace.commission_ledger' => 'Commission ledger', 'marketplace.all_states' => 'All statuses', 'marketplace.filter_ledger' => 'Filter ledger by status', 'marketplace.filter' => 'Filter', 'marketplace.ledger_error' => 'Could not load the ledger. The API key needs the partner:read scope.', 'marketplace.ledger_empty' => 'No commission line for this filter.', 'marketplace.statement_lines_empty' => 'This statement has no lines.', 'marketplace.not_provided' => 'Not provided', 'marketplace.reference' => 'Reference', 'marketplace.source' => 'Source', 'marketplace.accrued_at' => 'Accrued at', 'marketplace.invoice_prefix' => 'Invoice {id}', 'marketplace.lines_page_of' => 'Page {page} of {pages} · {total} line(s)',
    'marketplace.status.cleared' => 'Cleared', 'marketplace.status.confirmed' => 'Confirmed', 'marketplace.status.paid' => 'Paid', 'marketplace.status.active' => 'Active', 'marketplace.status.completed' => 'Completed', 'marketplace.status.pending' => 'Pending', 'marketplace.status.open' => 'Open', 'marketplace.status.processing' => 'Processing', 'marketplace.status.provisioning' => 'Provisioning', 'marketplace.status.reversed' => 'Reversed', 'marketplace.status.refunded' => 'Refunded', 'marketplace.status.chargeback' => 'Chargeback', 'marketplace.status.cancelled' => 'Cancelled', 'marketplace.status.canceled' => 'Canceled', 'marketplace.status.failed' => 'Failed', 'marketplace.status.suspended' => 'Suspended',
];
