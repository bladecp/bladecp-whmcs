<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'experience.requirements_title' => 'Requisitos y compatibilidad',
    'experience.expiry_title' => 'Después de la prueba o del plazo de pago',
    'experience.products_help' => 'Selecciona los productos que el cliente puede elegir. Todos los productos seleccionados siguen la misma política comercial.',
    'experience.products_other' => 'Otros productos',
    'experience.wordpress_label' => 'Mostrar WordPress Toolkit',
    'experience.wordpress_description' => 'Permite consultar y actualizar la instalación WordPress disponible en los servicios elegibles. Cada acción se limita al servicio del cliente.',
    'experience.expiry_action' => 'Después de la tolerancia',
    'experience.expiry_choose' => 'Vencimiento aún no configurado',
    'experience.expiry_manual' => 'Derivar para seguimiento manual',
    'experience.expiry_suspend' => 'Suspender sin eliminación automática',
    'experience.expiry_suspend_delete' => 'Suspender y eliminar después de la retención',
    'experience.expiry_grace_days' => 'Tolerancia tras el vencimiento (días)',
    'experience.expiry_retention_days' => 'Retención tras la suspensión (días)',
    'experience.expiry_help' => 'Se aplica a la prueba y a la contratación posterior. Tolerancia: de 0 a 365 días (0 significa sin tolerancia). Retención: de 1 a 365 días, solo para eliminación. La opción manual requiere seguimiento del partner. Estas condiciones son comunes a todos los planes y no se aplican al pago previo.',
    'experience.policy_title' => 'Política comercial de Fast Deploy',
    'experience.policy_description' => 'Elige una modalidad para todos los planes de este servidor. El cliente elige solo el plan y la ubicación. Los precios y ciclos siguen en los productos de WHMCS.',
    'experience.policy_default' => 'Modalidad para todos los planes',
    'experience.policy_mode' => 'Modalidad',
    'experience.policy_choose' => 'Selecciona una modalidad',
    'experience.policy_payment_before' => 'Pagar antes de migrar',
    'experience.policy_payment_after' => 'Migrar y pagar para activar',
    'experience.policy_trial' => 'Migrar y probar',
    'experience.policy_trial_days' => 'Duración de prueba (días)',
    'experience.policy_payment_days' => 'Plazo para contratar (días)',
    'experience.policy_days_help' => 'Introduce de 1 a 365 días para la modalidad elegida. Los demás campos se ignoran. El plazo de contratación aplica cuando la migración está lista para aprobación.',
    'experience.policy_products' => 'Productos disponibles en Fast Deploy',
    'experience.policy_no_products' => 'Ningún producto de hosting recurrente está asociado exclusivamente a este servidor.',
    'experience.policy_hidden' => 'Producto oculto o retirado: se puede configurar, pero no se mostrará al cliente.',
    'experience.policy_integration_pending' => 'Las reglas se guardan para cada nueva migración. Los pagos se verifican en WHMCS; los plazos y las acciones siguen la política elegida.',
    'experience.policy_save' => 'Guardar política comercial',
    'experience.policy_failed' => 'No se pudo guardar la política. Revisa la modalidad, su plazo y los productos elegidos.',

    'experience.deploy_label' => 'Mostrar Fast Deploy',
    'experience.deploy_description' => 'Muestra la migración guiada en el área de clientes, independientemente de Vibe y WordPress Toolkit.',
    'layout.tabs.client_experience' => 'Área de cliente',
    'experience.description' => 'Recursos opcionales por servidor BladeCP.',
    'experience.vibe_label' => 'Mostrar el formulario de ideas Vibe',
    'experience.vibe_description' => 'Añade el formulario a la página inicial de clientes elegibles. Desactivarlo solo elimina la tarjeta.',
    'experience.requirements' => 'La primera versión requiere una oferta Vibe Free única y un único servidor elegible en el grupo del producto. El envío requiere HTTPS y un origen autorizado en Vibe. Activar la tarjeta no registra ni comprueba esa autorización.',
    'experience.compatibility' => 'Compatibilidad: temas que muestran el contenido nativo ClientAreaHomepage (addons_html). Nexus y el tema del demo usan este punto de extensión.',
    'experience.unknown_environment' => 'El entorno de este servidor no tiene un destino Vibe reconocido. La tarjeta permanecerá oculta.',
    'experience.select_server' => 'Selecciona un servidor BladeCP válido en la configuración antes de activar recursos.',
    'experience.save' => 'Guardar configuración',
    'experience.saved' => 'Configuración guardada.',
    'experience.failed' => 'No se pudo guardar. Revisa el servidor seleccionado e inténtalo de nuevo.',
    'experience.migration_billing_title' => 'Cobro del cPanel Migrator',
    'experience.migration_billing_description' => 'Crea en WHMCS el servicio de cada migración hecha por el cPanel Migrator con la clave de este servidor, para que usted la cobre. Funciona con Fast Deploy activado o desactivado y no muestra nada a los clientes.',
    'experience.migration_billing_label' => 'Cobrar migraciones del cPanel Migrator',
];
