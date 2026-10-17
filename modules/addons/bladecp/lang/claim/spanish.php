<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'title' => 'Confirme su cuenta',
    'intro' => 'El alojamiento de {domain} quedará vinculado a esta cuenta, y sus próximos cobros llegarán por aquí.',
    'site' => 'Sitio',
    'account' => 'Cuenta',
    'confirm' => 'Confirmar',
    'note' => 'No se cobra nada ahora. El cobro sigue las condiciones indicadas en el panel de su alojamiento.',
    'done_title' => 'Listo.',
    'done' => 'Puede volver a su panel.',
    'login' => 'Inicie sesión o cree su cuenta para confirmar la migración.',
    'owner_only' => 'Inicie sesión con el usuario propietario de esta cuenta para confirmar la migración.',
    'expired' => 'Este enlace ha expirado. Abra la confirmación de nuevo desde el panel de su alojamiento.',
    'invalid' => 'Este enlace no es válido. Abra la confirmación de nuevo desde el panel de su alojamiento.',
    'used' => 'Esta migración ya fue confirmada. Si fue usted, puede volver a su panel.',
    'ended' => 'Esta migración ya no espera confirmación.',
    'profile' => 'Complete el nombre y el correo de su perfil e inténtelo de nuevo.',
    'unavailable_provider' => 'No es posible confirmar ahora. Su proveedor ya fue avisado.',
    'unavailable' => 'No es posible confirmar ahora. Inténtelo de nuevo en unos minutos.',
    'session' => 'Su sesión expiró. Confirme de nuevo.',
    'failed' => 'No es posible confirmar ahora. Inténtelo de nuevo en unos minutos.',
];
