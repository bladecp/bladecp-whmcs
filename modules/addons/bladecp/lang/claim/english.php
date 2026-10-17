<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'title' => 'Confirm your account',
    'intro' => 'The hosting of {domain} will be linked to this account, and its next charges will come through here.',
    'site' => 'Site',
    'account' => 'Account',
    'confirm' => 'Confirm',
    'note' => 'Nothing is charged now. Billing follows the terms shown in your hosting panel.',
    'done_title' => 'Done.',
    'done' => 'You can go back to your panel.',
    'login' => 'Sign in or create your account to confirm the migration.',
    'owner_only' => 'Sign in with the owner user of this account to confirm the migration.',
    'expired' => 'This link has expired. Open the confirmation again from your hosting panel.',
    'invalid' => 'This link is not valid. Open the confirmation again from your hosting panel.',
    'used' => 'This migration has already been confirmed. If it was you, you can go back to your panel.',
    'ended' => 'This migration is no longer waiting for confirmation.',
    'profile' => 'Complete the name and email of your profile and try again.',
    'unavailable_provider' => 'The confirmation is not possible right now. Your provider has been notified.',
    'unavailable' => 'The confirmation is not possible right now. Try again in a few minutes.',
    'session' => 'Your session expired. Please confirm again.',
    'failed' => 'The confirmation is not possible right now. Try again in a few minutes.',
];
