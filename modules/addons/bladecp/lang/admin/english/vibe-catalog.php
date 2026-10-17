<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'admin.vibe.details' => 'Readiness and synchronization',
    'admin.vibe.sync' => 'Sync Vibe offers',
    'admin.vibe.unknown' => 'Diagnostics unavailable. Refresh the catalog or check the integration in the partner portal.',
    'admin.vibe.ready' => 'Technical plan available for import.',
    'admin.vibe.plan_configuration_required' => 'Complete the technical plan configuration in the partner portal.',
    'admin.vibe.plan_disabled' => 'Plan disabled by the platform administrator.',
    'admin.vibe.channel_disabled' => 'WHMCS channel disabled for this plan.',
    'admin.vibe.enrollment_inactive' => 'Ask the platform administrator to activate Vibe resale in this environment.',
    'admin.vibe.ai_mode_not_allowed' => 'The plan AI mode is not authorized for this reseller.',
    'admin.vibe.credentials_not_ready' => 'Validate a supported AI credential under Vibe in the partner portal for this environment.',
    'admin.vibe.managed_ai_dependencies' => 'Wait for the platform administrator to enable Managed AI dependencies.',
    'admin.vibe.profile' => 'Review and activate the commercial profile with the WHMCS Addon channel in platform administration. Saving a draft does not activate it.',
    'admin.vibe.host' => 'Commercial handoff points to {host}; this WHMCS uses {local}. Review the URLs and allowed hosts in this environment commercial profile.',
    'admin.vibe.mapping' => 'The remote catalog is linked to product {remote}; the local product is {local}. Request a mapping review before syncing.',
    'admin.vibe.import' => 'Import the plan into this WHMCS when available. Then configure its price and sync offers.',
    'admin.vibe.import_free' => 'Import the free plan into this WHMCS when available. No paid offer price is required.',
    'admin.vibe.offer_disabled' => 'Offer disabled administratively. Synchronization does not reactivate it.',
    'admin.vibe.price' => 'Price missing or expired. Configure a price on the visible product in this WHMCS and sync Vibe offers.',
    'admin.vibe.attempt.synced' => 'Last synchronization accepted by the API: {count} products. Publication also requires plan readiness and an active commercial profile.',
    'admin.vibe.attempt.snapshot-incomplete' => 'Synchronization not sent: incomplete catalog. Check the connection and Vibe product mappings and tiers for this server.',
    'admin.vibe.attempt.mapping-conflict' => 'Synchronization rejected: incompatible product mapping or tier. Review local products and catalog mappings with the platform administrator.',
    'admin.vibe.attempt.sync-failed' => 'The last synchronization failed. Check this server connection to the API in the same environment and retry.',
    'admin.vibe.attempt.invalid-server' => 'Select a valid BladeCP server to synchronize.',
    'admin.vibe.attempt.server-unavailable' => 'Server unavailable. Check the BladeCP connection configuration.',
    'admin.vibe.attempt.never' => 'No recent synchronization record for this server.',
];
