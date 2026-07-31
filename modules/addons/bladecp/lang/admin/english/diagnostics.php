<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'diagnostics.header.title' => 'Diagnostics',
    'diagnostics.header.description' => 'Read-only checks that clarify the configuration and any item that needs attention.',
    'diagnostics.hero.eyebrow' => 'SAFE CHECKS',
    'diagnostics.hero.title' => 'Understand the current state before making a change.',
    'diagnostics.hero.description' => 'These indicators never change products, orders, or credentials. When a value cannot be verified, it is shown as unavailable instead of assuming everything is correct.',
    'diagnostics.glossary.title' => 'Quick guide',
    'diagnostics.glossary.ready' => 'The required check completed and there is no known alert.',
    'diagnostics.glossary.attention' => 'There is a pending item or information to review before the next step.',
    'diagnostics.glossary.unavailable' => 'The module could not confirm this information now. Check the connection and try again.',
    'diagnostics.docs.prefix' => 'Need more context?',
    'diagnostics.docs.link' => 'Read the BladeCP documentation',
    'diagnostics.check.fallbackTitle' => 'Check',
    'diagnostics.checks.emptyTitle' => 'The checks could not be loaded.',
    'diagnostics.checks.emptyDescription' => 'Try again from within WHMCS.',
    'diagnostics.checks.overall' => 'OVERALL STATUS: {state}',
    'diagnostics.checks.title' => 'Connection, public URL, catalog, and routine',
    'diagnostics.checks.description' => 'Includes credentials, connectivity, receiver HTTPS URL, signature, catalog update, cron, and product integrity.',
    'diagnostics.actions.open' => 'Open',
    'diagnostics.actions.viewGuide' => 'View guide',
    'diagnostics.readiness.title' => 'Integration readiness',
    'diagnostics.readiness.description' => 'Summary of the module configuration guide.',
    'diagnostics.readiness.fallbackStep' => 'Step',
    'diagnostics.state.ready' => 'ready',
    'diagnostics.state.attention' => 'attention',
    'diagnostics.state.pending' => 'pending',
    'diagnostics.state.unavailable' => 'unavailable',
];
