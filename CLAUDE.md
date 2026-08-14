# CLAUDE.md

Guía para Claude Code al trabajar en este repositorio.

> Escrito contra el árbol real, verificado archivo a archivo. Si algo aquí no cuadra con el código, gana el código — y corrige este archivo.

## Qué es KarMCP

Plugin de WordPress que expone el sitio como **herramientas MCP** (Model Context Protocol), para que un agente (Claude, Cursor, …) pueda construir páginas de Elementor, gestionar contenido, medios, usuarios, plugins y temas, auditar rendimiento y seguridad, y manejar bloques de Gutenberg. Se apoya en el **WordPress MCP Adapter**, que va empaquetado en `vendor/`.

Es un **producto independiente con marca propia**. No se presenta como derivado de nada: ni en el README, ni en el `readme.txt`, ni en la interfaz de administración. La atribución de copyright que exige la GPL vive en `NOTICE` y en `LICENSE`, y esos dos archivos no se tocan. El seguimiento técnico del árbol del que se derivó es interno: [docs/MAINTENANCE-UPSTREAM.md](docs/MAINTENANCE-UPSTREAM.md).

**Elementor es opcional.** Todos los dominios de WordPress funcionan sin él.

## Identidad y convenciones

| Cosa | Valor |
|---|---|
| Clases | `KarMCP_*` |
| Funciones, hooks, opciones | `karmcp_*` |
| Constantes | `KARMCP_*` |
| Text domain | `karmcp` |
| Namespace de abilities | `karmcp/<tool>` |
| Servidor MCP | `/wp-json/mcp/karmcp-server` |
| Nombre de herramienta MCP | `karmcp-<tool>` (el adapter sustituye `/` por `-`) |
| Versión actual | `1.0.0` — en `karmcp.php` (cabecera + `KARMCP_VERSION`) y `readme.txt` (`Stable tag`); los tres tienen que coincidir |

**Los `@since` de 2.x y 3.x del código no son releases de KarMCP.** Vienen del árbol del que deriva y se dejaron como están: reescribirlos en masa falsearía más de lo que aclara. La numeración de KarMCP empieza en 1.0.0, así que **cualquier `@since` nuevo se escribe con la versión actual**.

Estándares de WordPress, estrictos: `snake_case` en funciones y variables, `Upper_Snake_Case` en clases, sanitizar entrada, escapar salida, `$wpdb->prepare()`, nonces y capacidades siempre. Código y comentarios **en inglés**; la documentación de proyecto (este archivo, el roadmap) en español.

## Tests

La suite corre sobre stubs, sin instalar WordPress. Desde la raíz:

```bash
composer install && vendor/bin/phpunit
```

**En la máquina de desarrollo actual (Windows) no hay Composer en el PATH** y el PHP de winget trae las extensiones desactivadas. Para correr la suite sin Composer: descarga `phpunit-10.phar` de phar.phpunit.de y arráncalo activando `mbstring` por línea de comandos.

```bash
PHPDIR=$(dirname "$(which php)")
php -d extension_dir="$PHPDIR/ext" -d extension=mbstring /ruta/a/phpunit.phar
```

Estado de referencia: **173 tests, 456 aserciones, todo en verde** (2026-08-14). Los tests viven en `tests/`, nombrados `AlgoTest.php`, y prueban lógica pura (validadores, mapeo de esquemas, enrutado de dispatchers, delegación de permisos). Lo que toca el render real del front-end necesita verificación manual en un WordPress local.

## Arquitectura

### Flujo de registro

Tres hooks, en este orden:

1. `wp_abilities_api_categories_init` → registra la categoría `karmcp`
2. `wp_abilities_api_init` → registra las abilities vía `karmcp_register_ability()` (envoltorio en `includes/class-schema-compat.php:304`)
3. `mcp_adapter_init` → crea el servidor MCP con la lista de abilities

> **Trampa cara:** si omites `'category' => 'karmcp'` al registrar, `wp_register_ability()` **descarta la ability en silencio**. Sin error, sin aviso: la herramienta simplemente no existe.

### Capas

1. **Datos** (`class-elementor-data.php`) — envoltorio de lectura/escritura sobre `_elementor_data`. **Nunca escribas ese meta directamente**: todo guardado pasa por `\Elementor\Plugin::$instance->documents->get()->save()`, que es lo que regenera el CSS e invalida caché. Una escritura cruda produce bugs que solo se ven en el front.
2. **Factory** (`class-element-factory.php`) — construye JSON válido de Elementor; cada elemento recibe un id hex de 7 caracteres.
3. **Esquemas** (`schemas/`) — genera JSON Schema a partir de los controles reales de Elementor. No se escriben a mano.
4. **Abilities** (`includes/abilities/`, 48 archivos) — las herramientas, agrupadas por dominio y coordinadas por `class-ability-registrar.php`.

### Widgets: catálogo, no herramientas

Los widgets son **datos** en `includes/widgets/` (`catalog-{free,pro,woo}.php`), servidos por `KarMCP_Widget_Catalog`. El flujo es **descubrir → inspeccionar → actuar**: `list-widgets` → `get-widget-schema` → `add-free-widget` / `add-pro-widget` → `update-widget`. Cualquier control válido de Elementor pasa aunque no esté en el catálogo.

### Módulos

Features que el admin enciende y apaga desde la pestaña **Modules**. Base `KarMCP_Module` + `KarMCP_Modules_Registry` en `includes/modules/`. Los activos se guardan en la opción `karmcp_active_modules` y arrancan en `init` (prioridad 5).

Módulos presentes: Themer, Redirects, Prompts, Brand Kits, Templates, Agent Skills, Cloud, Image Optimization, SVG Support.

> **Patrón de gating a respetar:** las abilities se registran en `wp_abilities_api_init`, que corre **antes** de que el módulo arranque en `init:5`. Por eso el registrar consulta el estático `is_enabled()` del módulo, nunca su instancia.

### Modo compacto (dispatcher)

Opción `karmcp_dispatcher_mode` (pestaña Tools, por defecto OFF). Encendida, el servidor expone solo 3 meta-herramientas — `list-tools`, `get-tool-schema`, `call-tool` — en vez de ~130, para clientes con tope de herramientas. `call-tool` **delega en el `check_permissions()` de cada ability destino**: no hay escalada de privilegios, y los toggles por herramienta siguen mandando.

## Lo que NO existe en este árbol

Esto ahorra horas: hay guards por todo el código que comprueban clases que **nunca están presentes**. No son bugs, son restos de una separación de tiers que se eliminó. No intentes "arreglarlos" cargando algo.

- **No hay `pro/`**, ni submódulo, ni `pro-manifest.txt`, ni `KarMCP_Pro_Loader`. Un solo tier.
- **No hay Freemius ni licenciamiento.** `KarMCP_License` y `karmcp_fs()` no existen (0 ocurrencias).
- **No hay auto-updater.** La cabecera lleva `Update URI: false`; se actualiza sustituyendo la carpeta.
- **Clases ausentes** que sus guards siempre resuelven a falso: `KarMCP_Migrate_Abilities`, `KarMCP_Block_Store`, `KarMCP_Widget_Generator`, y los grupos Woo / GeneratePress / Blocksy / EssentialAddons / PremiumAddons / UAE / Block Builder / System Kit / SEO / A11y / Widget Builder / Skills / Memory.
- **Guards que devuelven `false` literal:** `KarMCP_Widget_Loader::has_access()`, `KarMCP_Widget_Store::user_has_access()`.
- **Cloud está inerte:** `KarMCP_Cloud::DEFAULT_BASE_URL` está vacío a propósito, así que el plugin **no hace ninguna llamada saliente** salvo que se configure (`KARMCP_CLOUD_URL`, la opción `karmcp_cloud_base_url`, o el filtro homónimo).

## Seguridad: el modelo

Toda herramienta comprueba una capacidad real de WordPress antes de actuar — un agente solo puede hacer lo que su usuario podría hacer a mano.

- Lo que escribe, borra o afecta a todo el sitio **se entrega deshabilitado** y se activa desde **KarMCP → Tools**.
- Lo destructivo exige además `confirm: true`.
- Los administradores no son editables por MCP y no hay herramienta de borrado de usuarios.
- El acceso a ficheros está confinado a `ABSPATH`, con backup automático y log de auditoría; `wp-config.php` y `.htaccess` se rechazan.
- Los snippets PHP **nunca se ejecutan sin aprobación humana**: el agente crea borradores validados, pero solo un admin puede activarlos.

Al añadir una herramienta que escribe: súbele `DEFAULTS_VERSION` en `class-admin.php` y siembra su slug como deshabilitada.

## Trampas verificadas

- **`post_type` tiene un límite duro de 20 caracteres** (`wp_posts.post_type` es `varchar(20)`). El prefijo `karmcp_` es largo; un CPT que se pase **no se registra y falla en silencio**. Por eso el CPT del Themer es `karmcp_theme_tpl`, no `karmcp_theme_template`.
- **Nunca adivines nombres de campos o controles.** Elementor y Spectra aceptan cualquier clave que les mandes sin quejarse, así que un nombre erróneo parece funcionar y no hace nada. Léelo del plugin.
- **Nunca escribas firmas de malware íntegras en disco** (ni en tests). Los escáneres del hosting ponen el archivo en cuarentena, lo cual lo **deja a cero sin borrarlo**: el `require_once` tiene éxito, la clase nunca se declara, y el fatal aparece lejos del origen. Por eso `class-security-malware-audit.php` ensambla sus patrones desde fragmentos en tiempo de ejecución.
- **`render.php` de un bloque debe hacer `echo`, no `return`** — WordPress lo envuelve en su propio buffer e ignora el retorno.

## Deuda conocida

Auditado el 2026-08-14; pendiente de abordar:

- **`includes/admin/class-admin.php` son ~5.900 líneas** mezclando 6 responsabilidades. `get_tool_catalog()` es un único método de ~1.865 líneas que solo devuelve un array. La extracción natural es a archivos de datos, patrón que el repo ya usa en `includes/widgets/catalog-*.php`.
- **Duplicación en abilities:** 168 registros repiten el literal completo; solo `class-database-abilities.php` y `class-wpcli-abilities.php` lo factorizan en un helper `ability()`. Esa es la plantilla a seguir.
- **Código muerto:** unas 250 líneas inalcanzables y 3 endpoints AJAX de una feature eliminada (Memory) en `class-admin.php`.
- **Seguridad, pendiente (severidad media):** los redirects de `KarMCP_Url_Guard::safe_download()` no revalidan contra 169.254.169.254 (el validador estricto `ip_is_blocked()` ya existe, pero solo se usa en otra ruta); el registro dinámico de clientes OAuth no tiene rate-limit; y los `scope` OAuth se guardan pero no se aplican en `KarMCP_OAuth_Bearer::permission_callback()`.

## Documentos

| Archivo | Qué es |
|---|---|
| [docs/ROADMAP-SEO-A11Y-THEMER.md](docs/ROADMAP-SEO-A11Y-THEMER.md) | Plan real de las dos capacidades propias. Themer extendido: **hecho**. SEO y accesibilidad: pendiente, con los seams ya verificados. |
| [docs/MAINTENANCE-UPSTREAM.md](docs/MAINTENANCE-UPSTREAM.md) | Nota interna de mantenimiento: qué se ha revisado del árbol de origen y qué divergencias no deben reimportarse. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Cómo añadir una herramienta o una integración. |
| `NOTICE` / `LICENSE` | Atribución y licencia. **No tocar.** |
