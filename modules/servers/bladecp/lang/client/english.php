<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'cart_unavailable' => 'This product is temporarily unavailable because its automation has not been released yet. No charge or provisioning will start.',
    'cart_free_direct' => 'Free BladeCP Vibe is activated directly, without a cart. Use the button to create your free app to continue.',
    'cart_offer_expired' => 'The selected commercial offer has expired or does not match the requested plan. Go back to BladeCP Vibe and try again.',
    'cart_components_unconfirmed' => 'We could not confirm the chosen components right now. Try again in a moment. No charge or provisioning will start.',
    'cart_availability_unconfirmed' => 'The availability of this BladeCP product could not be confirmed. No charge or provisioning will start.',
    'cart_option_unavailable' => 'The option {option} in {category} is no longer available. Go back to the product configuration and choose another option. No charge or provisioning will start.',
    'cart_combo_unavailable' => 'The bundle {combo} is not available at the moment. Go back to the product configuration and choose another bundle. No charge or provisioning will start.',
    'cart_combo_unavailable_generic' => 'The bundle of this product is not available at the moment. No charge or provisioning will start.',
    'vibe_downgrade_unavailable' => 'Downgrading BladeCP Vibe Pro to Free is not available.',
    'sso_dedicated_unavailable' => 'This server does not offer single sign-on through the panel.',
    'sso_provision_missing' => 'Provision UUID is missing.',
    'sso_panel_link_invalid' => 'The API did not return a valid HTTPS link to access the panel.',
    'sso_vibe_inactive' => 'The BladeCP Vibe service must be active for access.',
    'sso_admin_unavailable' => 'Administrative access is unavailable.',
    'sso_unavailable' => 'Access is unavailable right now.',
    'sso_vibe_account_missing' => 'BladeCP Vibe Account ID is missing.',
    'sso_vibe_link_invalid' => 'The API did not return a valid temporary HTTPS link to access Vibe.',
    'password_not_used' => 'BladeCP services have no per-service password. Access is through your BladeCP account login: sign in to the BladeCP Panel to manage your service and rotate any database or Redis credentials there.',
    'vibe_no_password' => 'BladeCP Vibe does not use a password per service. Access goes through the BladeCP Vibe account.',
    'vibe_pro_activation_text' => 'Finish activating Vibe Pro.',
    'vibe_pro_activation_button' => 'Activate BladeCP Vibe',
];
