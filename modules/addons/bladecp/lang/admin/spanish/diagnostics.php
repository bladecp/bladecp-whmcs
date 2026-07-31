<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'diagnostics.header.title' => 'Diagnóstico',
    'diagnostics.header.description' => 'Comprobaciones de solo lectura para facilitar la configuración y explicar lo que requiere atención.',
    'diagnostics.hero.eyebrow' => 'COMPROBACIONES SEGURAS',
    'diagnostics.hero.title' => 'Comprenda el estado antes de realizar un cambio.',
    'diagnostics.hero.description' => 'Los indicadores no cambian productos, pedidos ni credenciales. Cuando no se puede comprobar un dato, se muestra como no disponible, sin asumir que todo está correcto.',
    'diagnostics.glossary.title' => 'Guía rápida',
    'diagnostics.glossary.ready' => 'La comprobación necesaria se completó y no hay alertas conocidas.',
    'diagnostics.glossary.attention' => 'Hay una tarea pendiente o información que debe revisarse antes del siguiente paso.',
    'diagnostics.glossary.unavailable' => 'El módulo no pudo confirmar esta información ahora. Compruebe la conexión e inténtelo de nuevo.',
    'diagnostics.docs.prefix' => '¿Necesita más contexto?',
    'diagnostics.docs.link' => 'Consulte la documentación de BladeCP',
    'diagnostics.check.fallbackTitle' => 'Comprobación',
    'diagnostics.checks.emptyTitle' => 'No se pudieron cargar las comprobaciones.',
    'diagnostics.checks.emptyDescription' => 'Inténtelo de nuevo desde WHMCS.',
    'diagnostics.checks.overall' => 'ESTADO GENERAL: {state}',
    'diagnostics.checks.title' => 'Conexión, URL pública, catálogo y rutina',
    'diagnostics.checks.description' => 'Incluye credenciales, conectividad, URL HTTPS del receptor, firma, actualización del catálogo, cron e integridad de los productos.',
    'diagnostics.actions.open' => 'Abrir',
    'diagnostics.actions.viewGuide' => 'Ver guía',
    'diagnostics.readiness.title' => 'Preparación de la integración',
    'diagnostics.readiness.description' => 'Resumen de la guía de configuración del módulo.',
    'diagnostics.readiness.fallbackStep' => 'Paso',
    'diagnostics.state.ready' => 'listo',
    'diagnostics.state.attention' => 'atención',
    'diagnostics.state.pending' => 'pendiente',
    'diagnostics.state.unavailable' => 'no disponible',
];
