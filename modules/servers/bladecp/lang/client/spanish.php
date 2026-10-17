<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

return [
    'cart_unavailable' => 'Este producto no está disponible temporalmente porque su automatización aún no fue liberada. No se iniciará ningún cobro ni aprovisionamiento.',
    'cart_free_direct' => 'BladeCP Vibe gratis se activa directamente, sin carrito. Use el botón para crear su app gratis y continuar.',
    'cart_offer_expired' => 'La oferta comercial seleccionada expiró o no corresponde al plan solicitado. Vuelva a BladeCP Vibe e inténtelo de nuevo.',
    'cart_components_unconfirmed' => 'No pudimos confirmar ahora los componentes elegidos. Inténtelo de nuevo en unos instantes. No se iniciará ningún cobro ni aprovisionamiento.',
    'cart_availability_unconfirmed' => 'No se pudo confirmar la disponibilidad de este producto BladeCP. No se iniciará ningún cobro ni aprovisionamiento.',
    'cart_option_unavailable' => 'La opción {option} en {category} ya no está disponible. Vuelva a la configuración del producto y elija otra opción. No se iniciará ningún cobro ni aprovisionamiento.',
    'cart_combo_unavailable' => 'El combo {combo} no está disponible en este momento. Vuelva a la configuración del producto y elija otro combo. No se iniciará ningún cobro ni aprovisionamiento.',
    'cart_combo_unavailable_generic' => 'El combo de este producto no está disponible en este momento. No se iniciará ningún cobro ni aprovisionamiento.',
    'vibe_downgrade_unavailable' => 'No está disponible bajar de BladeCP Vibe Pro a Free.',
    'sso_dedicated_unavailable' => 'Este servidor no ofrece inicio de sesión único desde el panel.',
    'sso_provision_missing' => 'Falta el Provision UUID.',
    'sso_panel_link_invalid' => 'La API no devolvió un enlace HTTPS válido para acceder al panel.',
    'sso_vibe_inactive' => 'El servicio BladeCP Vibe debe estar activo para el acceso.',
    'sso_admin_unavailable' => 'El acceso administrativo no está disponible.',
    'sso_unavailable' => 'El acceso no está disponible ahora.',
    'sso_vibe_account_missing' => 'Falta el BladeCP Vibe Account ID.',
    'sso_vibe_link_invalid' => 'La API no devolvió un enlace HTTPS temporal válido para acceder a Vibe.',
    'password_not_used' => 'Los servicios BladeCP no usan contraseña por servicio. El acceso se hace con el inicio de sesión de su cuenta BladeCP: entre al panel de BladeCP para gestionar el servicio y cambiar allí las credenciales de base de datos o Redis.',
    'vibe_no_password' => 'BladeCP Vibe no usa contraseña por servicio. El acceso se hace con la cuenta BladeCP Vibe.',
    'vibe_pro_activation_text' => 'Complete la activación de Vibe Pro.',
    'vibe_pro_activation_button' => 'Activar BladeCP Vibe',
];
