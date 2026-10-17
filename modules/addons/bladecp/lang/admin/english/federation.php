<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
return [
    'federation.title' => 'Automatic Vibe access',
    'federation.description' => 'Generate a dedicated signing key here and register only the public data with the platform administrator. Enable after the platform confirms support. Existing access remains unchanged until enabled.',
    'federation.enabled' => 'Federation enabled locally',
    'federation.disabled' => 'Federation disabled locally',
    'federation.publicRegistration' => 'Public registration data (contains no private key)',
    'federation.generate' => 'Generate installation key',
    'federation.rotate' => 'Rotate signing key',
    'federation.enable' => 'Check readiness and enable',
    'federation.disable' => 'Disable new federated access',
    'federation.confirm.rotate' => 'I understand rotation disables new access until the new public key is registered.',
    'federation.confirm.enable' => 'The platform administrator has registered this installation.',
    'federation.saved' => 'Configuration saved.',
    'federation.failed' => 'Configuration not changed. Check the selected server, registration and platform readiness.',
];

