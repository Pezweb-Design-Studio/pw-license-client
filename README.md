# pw/license-client

Cliente de licencias de los plugins premium de Pezweb. Valida la licencia contra el servidor
[`pw_licence`](https://github.com/Pezweb-Design-Studio/pw_licence) (REST `pzw/v1`), pinta la
pantalla de licencia y entrega actualizaciones automáticas.

Requiere PHP 8.0+ y, en el servidor, `pw_licence` **2.1.1+** (actualizaciones y vencimiento real).

## Uso

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/Pezweb-Design-Studio/pw-license-client" }],
"require": { "pw/license-client": "^2.3" }
```

Subclase por plugin, con los datos del producto de licencia en el servidor (admin de `pw_licence` →
Productos):

```php
namespace MiPlugin\Integration;

class LicenseClient extends \PezWeb\License\Client
{
    protected const VALIDATION_HASH = 'of_xxxxxxxxxxxxxxxx'; // hash del producto
    protected const HMAC_SECRET     = '…';                   // secreto HMAC del producto
    // protected const SERVER_URL   = 'https://pezweb.com';  // por defecto

    protected function get_text_domain(): string { return 'mi-plugin'; }
    protected function license_parent_slug(): string { return 'mi-plugin-dashboard'; } // menú padre
    protected function use_backend_ui(): bool { return true; } // si el plugin carga pw/backend-ui
    protected function get_plugin_name() { return 'Mi Plugin Pro'; }
}

// En plugins_loaded (sin __() aquí):
LicenseClient::init(['plugin_file' => MI_PLUGIN_FILE]); // plugin_file activa las actualizaciones

// Para condicionar funciones (no hace llamadas de red):
if (LicenseClient::check()) { /* funciones Pro */ }
```

Cada subclase tiene su propia instancia, opción de clave (`pzw_license_key_<hash>`), estado
(`pzw_license_state_<hash>`), pantalla (`pzw-license-<hash>`) y acciones AJAX, así que varios
plugins Pezweb conviven en un mismo sitio. La pantalla trae su JS (activar, desactivar, verificar).

## Validación resiliente (2.3)

- `check()` / `is_valid()` **solo leen el estado guardado**: las visitas nunca llaman al servidor.
- Revalidación: WP-Cron cada 6 h + evento puntual al vencer la firma; en el admin si el estado
  tiene más de 6 h; si el cron no corre, un disparo no bloqueante desde una visita con estado de
  más de 12 h (AJAX público sin parámetros, con lock y backoff antes de llamar al servidor).
- **Gracia:** si el servidor no responde (red, 5xx, 429, firma inválida) se mantiene la última
  validación firmada hasta `valid_until` + 7 días, sin pasar nunca del vencimiento real de la
  licencia (`license_expires`). Solo un `valid:false` firmado la corta antes. El admin muestra un aviso.
- Backoff 5 min / 15 min / 1 h / 6 h, respetando `Retry-After`. Timeout de validación: 5 s.

## Actualizaciones automáticas

Con `plugin_file`, `Updater` usa el filtro nativo `update_plugins_{host}`: el plugin **debe** declarar
`Update URI: https://pezweb.com/plugins/<slug>` en su cabecera. El servidor
(`POST pzw/v1/update/{hash}`) informa la última versión y, con licencia válida para el sitio, un
enlace de descarga firmado (48 h). El zip es el primer archivo descargable del producto
WooCommerce vinculado al producto de licencia. Si la carpeta raíz del zip no coincide con la
instalada, se renombra al actualizar (el plugin no queda desactivado).

## Desarrollo local

Apuntar el cliente a un servidor local con un mu-plugin:

```php
add_filter('pzw_license_server_url', static fn() => 'http://localhost/mi-servidor-de-licencias');
```

## Versiones

| Versión | Cambio |
|---|---|
| 2.3.0 | Validación resiliente (estado persistente, cron, gracia, backoff) |
| 2.2.1 | El updater mantiene la carpeta de instalación |
| 2.2.0 | Seguro con varios plugins, `pzw_license_server_url`, JS incluido, actualizaciones, vencimiento real |
