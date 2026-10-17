<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'admin.vibe.details' => 'Disponibilidad y sincronización',
    'admin.vibe.sync' => 'Sincronizar ofertas Vibe',
    'admin.vibe.unknown' => 'Diagnóstico no disponible. Actualice el catálogo o revise la integración en el portal del socio.',
    'admin.vibe.ready' => 'Plan técnico disponible para importar.',
    'admin.vibe.plan_configuration_required' => 'Complete la configuración técnica del plan en el portal del socio.',
    'admin.vibe.plan_disabled' => 'Plan desactivado por el administrador de la plataforma.',
    'admin.vibe.channel_disabled' => 'Canal WHMCS desactivado para este plan.',
    'admin.vibe.enrollment_inactive' => 'Solicite al administrador de la plataforma activar la reventa Vibe en este entorno.',
    'admin.vibe.ai_mode_not_allowed' => 'El modo de IA del plan no está autorizado para este revendedor.',
    'admin.vibe.credentials_not_ready' => 'Valide una credencial de IA compatible en Vibe, en el portal del socio de este entorno.',
    'admin.vibe.managed_ai_dependencies' => 'Espere a que el administrador de la plataforma habilite las dependencias de Managed AI.',
    'admin.vibe.profile' => 'Revise y active el perfil comercial con el canal Addon WHMCS en la administración de la plataforma. Guardar un borrador no lo activa.',
    'admin.vibe.host' => 'El handoff comercial apunta a {host}; este WHMCS usa {local}. Revise las URLs y los hosts autorizados del perfil comercial de este entorno.',
    'admin.vibe.mapping' => 'El catálogo remoto está vinculado al producto {remote}; el producto local es {local}. Solicite revisar el vínculo antes de sincronizar.',
    'admin.vibe.import' => 'Importe el plan en este WHMCS cuando esté disponible. Después configure el precio y sincronice las ofertas.',
    'admin.vibe.import_free' => 'Importe el plan gratuito en este WHMCS cuando esté disponible. No requiere precio de oferta de pago.',
    'admin.vibe.offer_disabled' => 'Oferta desactivada administrativamente. La sincronización no la reactiva.',
    'admin.vibe.price' => 'Precio ausente o vencido. Configure un precio en el producto visible de este WHMCS y sincronice las ofertas Vibe.',
    'admin.vibe.attempt.synced' => 'Última sincronización aceptada por la API: {count} productos. La publicación también requiere disponibilidad del plan y un perfil comercial activo.',
    'admin.vibe.attempt.snapshot-incomplete' => 'Sincronización no enviada: catálogo incompleto. Revise la conexión y los vínculos y categorías de los productos Vibe de este servidor.',
    'admin.vibe.attempt.mapping-conflict' => 'Sincronización rechazada: vínculo de producto o categoría incompatible. Revise los productos locales y los vínculos del catálogo con el administrador de la plataforma.',
    'admin.vibe.attempt.sync-failed' => 'La última sincronización falló. Revise la conexión de este servidor con la API del mismo entorno e inténtelo de nuevo.',
    'admin.vibe.attempt.invalid-server' => 'Seleccione un servidor BladeCP válido para sincronizar.',
    'admin.vibe.attempt.server-unavailable' => 'Servidor no disponible. Revise la configuración de la conexión BladeCP.',
    'admin.vibe.attempt.never' => 'Sin registro reciente de sincronización en este servidor.',
];
