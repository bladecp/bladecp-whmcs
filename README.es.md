<div align="center">

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
  <img alt="BladeCP" src="assets/logo-light.svg" width="268" height="48">
</picture>

# BladeCP para WHMCS

**Aprovisione y gestione servicios BladeCP directamente desde WHMCS.**

Versión candidata: **0.9.0-rc.4**

[![Release candidate](https://img.shields.io/badge/release-0.9.0--rc.4-4f46e5)](https://github.com/bladecp/bladecp-whmcs/releases)
[![Licencia](https://img.shields.io/badge/licencia-MIT-4f46e5)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-4f46e5?logo=php&logoColor=white)
![WHMCS 8.x y 9.x](https://img.shields.io/badge/WHMCS-8.x%20%7C%209.x-4f46e5)

[Descargar la versión más reciente](https://github.com/bladecp/bladecp-whmcs/releases) · [Instalar](#instalación) · [Configurar](#configuración) · [Seguridad](#seguridad) · [Proceso de release](docs/release-process.md) · [English](README.md) · [Português (Brasil)](README.pt-BR.md)

[![CI público](https://github.com/bladecp/bladecp-whmcs/actions/workflows/ci.yml/badge.svg)](https://github.com/bladecp/bladecp-whmcs/actions/workflows/ci.yml)

</div>

## Creado para partners de BladeCP

BladeCP para WHMCS es el módulo oficial de aprovisionamiento de código abierto para proveedores de hosting que venden servicios BladeCP desde su propia instalación WHMCS. Conecta un registro de servidor WHMCS a la Customer API de BladeCP, para que los pedidos y las acciones del servicio permanezcan en el flujo de facturación habitual del proveedor.

El módulo ofrece soporte para productos **Stack Container**, **Edge Cloud (VPS)** y **Dedicated** en un único paquete instalable. Los servicios Dedicated usan un ciclo de vida manual explícito cuando la plataforma lo requiere. Vibe no se aprovisiona mediante este módulo.

## De un vistazo

| Un módulo | Control operativo | Administración opcional |
| --- | --- | --- |
| Dirija productos Stack, Edge y Dedicated desde un único módulo WHMCS. | Cree, suspenda, reactive, cambie de plan, termine y abra el inicio de sesión único cuando la operación sea compatible con el producto seleccionado. | Active el addon complementario para configuración guiada, importación de catálogo, opciones configurables, webhooks y diagnósticos. |

## Explore la demostración en línea

Vea el módulo en un entorno WHMCS preparado: [whmcs-demo.bladecp.com](https://whmcs-demo.bladecp.com/).

Este entorno es solo para demostración. No introduzca información personal,
financiera ni credenciales reales.

## Vea el módulo en WHMCS

<p align="center">
  <img src="assets/screenshots/es/overview.jpg" alt="Resumen del addon BladeCP en una administración WHMCS en español" width="900">
</p>

| Configuración | Planes y catálogo |
| --- | --- |
| <img src="assets/screenshots/es/configuration.jpg" alt="Configuración de BladeCP en una administración WHMCS en español" width="440"> | <img src="assets/screenshots/es/plans.jpg" alt="Planes BladeCP e importación de catálogo en una administración WHMCS en español" width="440"> |

| Precios | Marketplace |
| --- | --- |
| <img src="assets/screenshots/es/pricing.jpg" alt="Precios BladeCP en una administración WHMCS en español" width="440"> | <img src="assets/screenshots/es/marketplace.jpg" alt="Marketplace BladeCP en una administración WHMCS en español" width="440"> |

| Operación y diagnóstico | Servicio contratado en el área de clientes |
| --- | --- |
| <img src="assets/screenshots/es/operations-diagnostics.jpg" alt="Operación y diagnóstico BladeCP en una administración WHMCS en español" width="440"> | <img src="assets/screenshots/es/client-service.jpg" alt="Panel de servicio contratado BladeCP en un área de clientes WHMCS en español usando example.com" width="440"> |

## Funcionalidades

### Aprovisionamiento y gestión de servicios

- Aprovisione servicios Stack, Edge y Dedicated elegibles desde pedidos de WHMCS.
- Suspenda, reactive, cambie de plan y termine servicios mediante acciones estándar de WHMCS cuando la operación sea compatible con el producto seleccionado.
- Abra el panel de BladeCP con inicio de sesión único.
- Muestre un panel de servicio con la marca BladeCP en el área de clientes de WHMCS.
- Sincronice el uso del servicio para los flujos de facturación de WHMCS.
- Registre la intención de cancelación con las acciones **Cancelar ahora** y **Cancelar al final del ciclo**.

### Addon opcional de BladeCP

El aprovisionador funciona sin el addon. Cuando está activado, el addon añade una interfaz de administración nativa de WHMCS para:

- Configuración guiada del servidor y validación de conexión.
- Importación idempotente del catálogo, de los planes BladeCP a los grupos y productos de WHMCS.
- Productos simples o configurables, combinaciones de componentes y sincronización de opciones configurables.
- Registro de webhooks, diagnósticos y una vista de solo lectura de la cuenta Marketplace.

## Compatibilidad

| Componente | Requisito o base compatible |
| --- | --- |
| WHMCS | 8.x y 9.x. Validado con 8.13.3 y 9.0.6. |
| PHP | 8.1 o posterior. |
| Base de datos | Cualquier motor y versión de base de datos compatible con la versión instalada de WHMCS. El módulo no añade ningún requisito específico de base de datos. |
| Temas de WHMCS | Temas que preserven los hooks y el marcado estándar de WHMCS. Validado con administración Blend y los temas de cliente Twenty-One, Six y Nexus. |
| Navegador | Navegadores modernos compatibles con WHMCS. El módulo no tiene ninguna dependencia específica de navegador. |
| Idiomas | Módulo orientado al cliente y administración del addon: inglés, portugués (Brasil) y español. |
| Dependencias de tiempo de ejecución | Extensiones de PHP cURL, JSON y OpenSSL |

La instantánea de validación de esta versión candidata incluye WHMCS 8.13.3 y
9.0.6, PHP 8.2.31 y MariaDB 12.3.2. Son entornos validados, no requisitos
mínimos adicionales. Pruebe la versión en una instalación WHMCS no productiva
antes de ponerla a disposición de sus clientes.

## Requisitos

- Acceso administrativo a una instalación WHMCS compatible.
- Una cuenta partner de BladeCP.
- Una clave de la Customer API de BladeCP.
- PHP con la extensión cURL activada.
- Un endpoint HTTPS público si activa webhooks entrantes.

## Instalación

Instale desde una [GitHub Release](https://github.com/bladecp/bladecp-whmcs/releases), no desde una instantánea del código fuente.

1. Descargue el ZIP de la versión y el archivo `SHA256SUMS` correspondiente.
2. Verifique el artefacto desde el directorio que contiene ambos archivos:

   ```bash
   shasum -a 256 -c SHA256SUMS
   ```

3. Extraiga el ZIP en la raíz de su instalación WHMCS. Al finalizar, deben existir estos directorios:

   ```text
   modules/servers/bladecp/
   modules/addons/bladecp/
   ```

   El ZIP instalable contiene solamente `modules/`. No sobrescribe el README,
   la licencia ni los archivos de documentación de la raíz de WHMCS.

4. En WHMCS, cree o seleccione un servidor en **System Settings → Products/Services → Servers**.
5. Configure la conexión con BladeCP y ejecute **Test Connection** antes de asignar el módulo a un producto.
6. Para usar la configuración guiada, herramientas de catálogo, opciones configurables o webhooks, active **BladeCP** en **System Settings → Addon Modules**.

## Configuración

### Recomendado: configuración guiada

Active el addon de BladeCP, abra el flujo **Quick Setup**, introduzca la clave de Customer API y valide la conexión. El asistente crea el registro de servidor BladeCP mediante los campos estándar de WHMCS.

### Configuración manual del servidor

Cree un servidor WHMCS con estos valores:

| Campo | Valor |
| --- | --- |
| Módulo | `bladecp` |
| Hostname | `api.bladecp.com` |
| Secure | Activado |
| Access Hash | Su clave de Customer API de BladeCP |

La clave de API debe almacenarse en el campo **Access Hash** del registro de servidor en WHMCS. El addon opcional no requiere una segunda copia de esta credencial.

A continuación, cree un producto WHMCS, seleccione el módulo `bladecp` y elija **BladeCP Product Type** y **BladeCP Plan**. El addon puede importar entradas de catálogo y sincronizar opciones configurables orientadas al cliente cuando ese flujo se adapte a su tienda.

> [!WARNING]
> Defina el hostname explícitamente. Un hostname vacío utiliza el endpoint de producción.

## Notas sobre el ciclo de vida de los productos

Stack y Edge están diseñados para el ciclo de aprovisionamiento regular de WHMCS. Los productos Dedicated están disponibles mediante el mismo módulo, pero su aprovisionamiento y ciclo de vida pueden requerir gestión manual explícita según el servicio ofrecido por la plataforma. Confirme las operaciones disponibles para el plan que vende antes de activar flujos automatizados para clientes.

## Seguridad

- Almacene la clave de Customer API únicamente en el registro de servidor de WHMCS.
- Trate las claves de API, los secretos de firma de webhooks y los registros del módulo como datos operativos sensibles.
- Los webhooks entrantes verifican sus firmas antes de aplicar una actualización de servicio.
- Mantenga WHMCS, PHP y este módulo actualizados con la versión que haya validado.

Para informar una vulnerabilidad de forma privada, siga [SECURITY.md](SECURITY.md). No incluya credenciales, payloads de webhook ni datos de clientes en una issue pública.

## Releases y soporte

Cada versión publicada ofrece el ZIP instalable y su suma de comprobación SHA-256. Revise las notas de la versión antes de actualizar: [releases de BladeCP para WHMCS](https://github.com/bladecp/bladecp-whmcs/releases).

- Soporte técnico y de producto: [dev@bladecp.com](mailto:dev@bladecp.com)
- Bugs y solicitudes de producto: [GitHub Issues](https://github.com/bladecp/bladecp-whmcs/issues)

## Contribuir

Las contribuciones son bienvenidas. Lea [CONTRIBUTING.md](CONTRIBUTING.md) antes de abrir una issue o pull request, mantenga los cambios enfocados, añada cobertura relevante y no incluya nunca credenciales ni datos de clientes en un commit. El repositorio privado de Bitbucket es la fuente principal de desarrollo: las contribuciones aprobadas de GitHub se importan allí antes de volver en una exportación pública posterior.

## Licencia

BladeCP para WHMCS se distribuye bajo la [Licencia MIT](LICENSE).
