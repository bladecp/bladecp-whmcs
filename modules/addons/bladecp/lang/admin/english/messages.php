<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'addon.config.description' => 'Component bundles and Configurable Options sync for the order form. No credentials are stored here: the API key lives in the Server record (Setup → Products/Services → Servers).',
    'addon.activate.success' => 'Bundle and configurable option tables created. Open the addon to manage bundles and synchronize configurable options.',
    'addon.activate.failed' => 'The module tables could not be prepared.',
    'addon.deactivate.success' => 'The addon was deactivated. Catalog, links, bundles and history were kept for a safe reactivation.',
    'addon.output.failed' => 'This BladeCP screen could not be loaded. Try again and check the module diagnostics.',
    'admin.vibe_support.title' => 'Vibe support',
    'admin.vibe_support.subtitle' => 'Administrative access limited to the selected service.',
    'admin.vibe_support.select' => 'Select an active Vibe service to start support.',
    'admin.operation.default_action' => 'BladeCP action',
    'admin.operation.failure_recorded' => 'Failure recorded. Check the module log for technical details.',
    'admin.operation.action_recorded' => 'Action recorded by the module.',
    'admin.combos.name_required' => 'The bundle name is required.',
    'admin.combos.database_unavailable' => 'Database unavailable.',
    'admin.webhooks.replay_invalid' => 'Invalid delivery.',
    'admin.webhooks.replay_unconfirmed' => 'The API did not confirm the resend.',
    'admin.setup.server_module_unavailable' => 'Server module unavailable.',
    'admin.setup.credential_save_failed' => 'The credential could not be protected and saved. No change was applied.',
    'plans.import.database_unavailable' => 'Capsule unavailable.',
    'plans.import.missing_plan_id' => 'The catalog did not provide the plan id.',
    'plans.import.server_required' => 'Select a dedicated BladeCP server before importing plans.',
    'plans.import.failed' => 'The import could not be completed right now. No partial change was saved. Try again.',
    'plans.import.lock_unavailable' => 'The catalog import lock could not be obtained. Try again.',
    'plans.import.options_not_updated' => 'The existing product was kept, but its operational options could not be updated.',
    'plans.import.plan_unavailable' => 'Plan unavailable for this module.',
    'plans.import.invalid_plan_id' => 'The catalog did not provide a valid numeric id for the plan.',
    'plans.import.no_location' => 'The plan has no active, linked location.',
    'admin.vibe_support.cta_group' => 'BladeCP access',
    'admin.vibe_support.cta_main_title' => 'Open this client\'s Vibe for support',
    'admin.vibe_support.cta_more' => 'Other BladeCP access options',
    'admin.vibe_support.cta_account' => 'Access through the BladeCP account',
    'admin.setup.select_server_first' => 'First select the BladeCP server used by the administration screens.',
    'admin.combos.component_required' => 'Select at least one component.',
    'admin.setup.starter_server_required' => 'Select a dedicated BladeCP server before creating the test product.',
];
