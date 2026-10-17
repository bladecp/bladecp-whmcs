<?php
declare(strict_types=1);
if (!defined('WHMCS')) { die('This file cannot be accessed directly'); }
return [
    'federation.title' => 'Acceso automático a Vibe',
    'federation.description' => 'Genere aquí una clave de firma exclusiva y registre solo los datos públicos con el administrador de la plataforma. Active después de que la plataforma confirme el soporte. El acceso actual no cambia hasta la activación.',
    'federation.enabled' => 'Federación activada localmente',
    'federation.disabled' => 'Federación desactivada localmente',
    'federation.publicRegistration' => 'Datos públicos de registro (no contienen la clave privada)',
    'federation.generate' => 'Generar clave de instalación',
    'federation.rotate' => 'Rotar clave de firma',
    'federation.enable' => 'Verificar disponibilidad y activar',
    'federation.disable' => 'Desactivar nuevos accesos federados',
    'federation.confirm.rotate' => 'Entiendo que la rotación desactiva nuevos accesos hasta registrar la nueva clave pública.',
    'federation.confirm.enable' => 'El administrador de la plataforma registró esta instalación.',
    'federation.saved' => 'Configuración guardada.',
    'federation.failed' => 'Configuración sin cambios. Verifique el servidor seleccionado, el registro y la disponibilidad de la plataforma.',
];

