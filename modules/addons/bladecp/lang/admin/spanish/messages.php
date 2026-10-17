<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'addon.config.description' => 'Combos de componentes y sincronización de Configurable Options en el formulario de pedido. No guarda credenciales: la API key queda en el Server record (Setup → Products/Services → Servers).',
    'addon.activate.success' => 'Tablas de combos y opciones configurables creadas. Abra el addon para gestionar los combos y sincronizar las opciones configurables.',
    'addon.activate.failed' => 'No se pudieron preparar las tablas del módulo.',
    'addon.deactivate.success' => 'El addon fue desactivado. Catálogo, vínculos, combos e historial se conservaron para una reactivación segura.',
    'addon.output.failed' => 'No se pudo cargar esta pantalla de BladeCP. Inténtelo de nuevo y consulte el diagnóstico del módulo.',
    'admin.vibe_support.title' => 'Atención Vibe',
    'admin.vibe_support.subtitle' => 'Acceso administrativo limitado al servicio seleccionado.',
    'admin.vibe_support.select' => 'Seleccione un servicio Vibe activo para iniciar la atención.',
    'admin.operation.default_action' => 'Acción BladeCP',
    'admin.operation.failure_recorded' => 'Fallo registrado. Consulte el log del módulo para los detalles técnicos.',
    'admin.operation.action_recorded' => 'Acción registrada por el módulo.',
    'admin.combos.name_required' => 'El nombre del combo es obligatorio.',
    'admin.combos.database_unavailable' => 'Base de datos no disponible.',
    'admin.webhooks.replay_invalid' => 'Entrega no válida.',
    'admin.webhooks.replay_unconfirmed' => 'La API no confirmó el reenvío.',
    'admin.setup.server_module_unavailable' => 'Módulo de servidor no disponible.',
    'admin.setup.credential_save_failed' => 'No se pudo proteger y guardar la credencial. No se aplicó ningún cambio.',
    'plans.import.database_unavailable' => 'Capsule no disponible.',
    'plans.import.missing_plan_id' => 'El catálogo no informó el id del plan.',
    'plans.import.server_required' => 'Seleccione un servidor BladeCP exclusivo antes de importar planes.',
    'plans.import.failed' => 'No se pudo completar la importación ahora. No se guardó ningún cambio parcial. Inténtelo de nuevo.',
    'plans.import.lock_unavailable' => 'No se pudo obtener el bloqueo de importación del catálogo. Inténtelo de nuevo.',
    'plans.import.options_not_updated' => 'El producto existente se conservó, pero sus opciones operativas no se pudieron actualizar.',
    'plans.import.plan_unavailable' => 'Plan no disponible para este módulo.',
    'plans.import.invalid_plan_id' => 'El catálogo no informó un id numérico válido para el plan.',
    'plans.import.no_location' => 'El plan no tiene una location activa y vinculada.',
    'admin.vibe_support.cta_group' => 'Acceso BladeCP',
    'admin.vibe_support.cta_main_title' => 'Abrir el Vibe de este cliente para atención',
    'admin.vibe_support.cta_more' => 'Otras formas de acceso BladeCP',
    'admin.vibe_support.cta_account' => 'Acceder con la cuenta BladeCP',
    'admin.setup.select_server_first' => 'Seleccione primero el servidor BladeCP usado por las pantallas administrativas.',
    'admin.combos.component_required' => 'Seleccione al menos un componente.',
    'admin.setup.starter_server_required' => 'Seleccione un servidor BladeCP exclusivo antes de crear el producto de prueba.',
];
