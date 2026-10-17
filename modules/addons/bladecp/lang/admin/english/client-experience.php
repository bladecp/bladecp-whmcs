<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'experience.requirements_title' => 'Requirements and compatibility',
    'experience.expiry_title' => 'After the trial or payment deadline',
    'experience.products_help' => 'Select the products clients can choose. All selected products follow the same commercial policy.',
    'experience.products_other' => 'Other products',
    'experience.wordpress_label' => 'Show WordPress Toolkit',
    'experience.wordpress_description' => 'View and update the WordPress installation available on eligible services. Every action is restricted to the customer service.',
    'experience.expiry_action' => 'After the grace period',
    'experience.expiry_choose' => 'Expiry not configured yet',
    'experience.expiry_manual' => 'Refer for manual follow-up',
    'experience.expiry_suspend' => 'Suspend without automatic deletion',
    'experience.expiry_suspend_delete' => 'Suspend and delete after retention',
    'experience.expiry_grace_days' => 'Grace period after expiry (days)',
    'experience.expiry_retention_days' => 'Retention after suspension (days)',
    'experience.expiry_help' => 'Applies to trials and payment after migration. Grace: 0 to 365 days (0 means no grace period). Retention: 1 to 365 days, only for deletion. Manual handling requires partner follow-up. These conditions apply to all plans and do not apply to payment before migration.',
    'experience.policy_title' => 'Fast Deploy commercial policy',
    'experience.policy_description' => 'Choose one payment policy for all plans on this server. Clients choose only their plan and location. Prices and billing cycles remain in WHMCS products.',
    'experience.policy_default' => 'Payment policy for all plans',
    'experience.policy_mode' => 'Policy',
    'experience.policy_choose' => 'Select a policy',
    'experience.policy_payment_before' => 'Pay before migrating',
    'experience.policy_payment_after' => 'Migrate and pay to activate',
    'experience.policy_trial' => 'Migrate and try',
    'experience.policy_trial_days' => 'Trial duration (days)',
    'experience.policy_payment_days' => 'Time to purchase (days)',
    'experience.policy_days_help' => 'Enter 1 to 365 days for the selected policy. Other fields are ignored. The purchase window applies after migration is ready for approval.',
    'experience.policy_products' => 'Products available in Fast Deploy',
    'experience.policy_no_products' => 'No recurring hosting product is exclusively associated with this server.',
    'experience.policy_hidden' => 'Hidden or retired product: settings can be saved but it will not be shown to clients.',
    'experience.policy_integration_pending' => 'Rules are frozen for each new migration. Payments are verified in WHMCS; deadlines and actions follow the selected policy.',
    'experience.policy_save' => 'Save commercial policy',
    'experience.policy_failed' => 'Could not save the policy. Check the policy, its duration and the selected products.',

    'experience.deploy_label' => 'Show Fast Deploy',
    'experience.deploy_description' => 'Shows guided migration in the client area, independently of Vibe and WordPress Toolkit.',
    'layout.tabs.client_experience' => 'Client area',
    'experience.description' => 'Optional features for each BladeCP server.',
    'experience.vibe_label' => 'Show the Vibe prompt card',
    'experience.vibe_description' => 'Adds the idea form to eligible client homepages. Switching it off only removes the card.',
    'experience.requirements' => 'The first version requires one canonical Vibe Free offer and a single eligible server in its product group. Sending requires HTTPS and an origin authorized by Vibe. Enabling the card does not register or verify that authorization.',
    'experience.compatibility' => 'Compatibility: themes that render native ClientAreaHomepage content (addons_html). Nexus and the demo theme use this extension point.',
    'experience.unknown_environment' => 'This server environment has no recognized Vibe destination. The card will remain hidden.',
    'experience.select_server' => 'Select a valid BladeCP server in setup before enabling features.',
    'experience.save' => 'Save settings',
    'experience.saved' => 'Settings saved.',
    'experience.failed' => 'Could not save. Check the selected server and try again.',
    'experience.migration_billing_title' => 'cPanel Migrator billing',
    'experience.migration_billing_description' => 'Creates in WHMCS the service of each migration made by the cPanel Migrator with the key of this server, so you can bill it. It works with Fast Deploy on or off and shows nothing to clients.',
    'experience.migration_billing_label' => 'Bill cPanel Migrator migrations',
];
