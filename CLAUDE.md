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
| Versión actual | `1.7.4` — en `karmcp.php` (cabecera + `KARMCP_VERSION`) y `readme.txt` (`Stable tag`); los tres tienen que coincidir |

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

Estado de referencia: **496 tests, 1.120 aserciones, todo en verde** (2026-08-14). Los tests viven en `tests/`, nombrados `AlgoTest.php`, y prueban lógica pura (validadores, mapeo de esquemas, enrutado de dispatchers, delegación de permisos). Lo que toca el render real del front-end necesita verificación manual en un WordPress local.

El harness comparte stubs en `tests/bootstrap.php`, y ahí está la trampa: **un stub del harness gana al que declare un fichero de test**, porque el bootstrap carga primero. Si añades ahí una función que un test ya simulaba por su cuenta, ese test empieza a leer una fixture distinta y falla lejos del cambio. Pasó con `wp_get_object_terms()` y los menús.

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
4. **Abilities** (`includes/abilities/`, 66 archivos) — las herramientas, agrupadas por dominio y coordinadas por `class-ability-registrar.php`.

### El bucle cerrado: render-page

`KarMCP_Content_Extractor` (`includes/class-content-extractor.php`) renderiza un post como lo recibe un visitante y lo reduce a un digest normalizado. Está partido a propósito: `analyze()` es **puro** (HTML entra, digest sale, sin WordPress) y es lo que se testea; `extract()` es la mitad que resuelve un post a HTML. No reinventa nada: el render delega en `KarMCP_Themer_Content_Renderer::render()` y el `scope: full` en `KarMCP_Performance_Page_Audit::fetch()`, que ya revalida cada salto de redirección contra el host de origen.

Es la base compartida que pide la Parte 2 del roadmap: `audit-page-seo` y `audit-page-a11y` consumen esta misma vista.

### Integraciones de plugin: el trait

Las integraciones exponen dos herramientas (`<id>-read` / `<id>-write`) que reciben `{ operation, arguments }`. La mecánica común —resolver la operación, revalidar su capacidad, exigir `confirm` en las destructivas, devolver el catálogo cuando no se nombra ninguna— vive en `includes/abilities/trait-operation-dispatcher.php`. La base de formularios es anterior y conserva su copia; **lo nuevo usa el trait**.

### Widgets: catálogo, no herramientas

Los widgets son **datos** en `includes/widgets/` (`catalog-{free,pro,woo}.php`), servidos por `KarMCP_Widget_Catalog`. El flujo es **descubrir → inspeccionar → actuar**: `list-widgets` → `get-widget-schema` → `add-free-widget` / `add-pro-widget` → `update-widget`. Cualquier control válido de Elementor pasa aunque no esté en el catálogo.

### Módulos

Features que el admin enciende y apaga desde la pestaña **Modules**. Base `KarMCP_Module` + `KarMCP_Modules_Registry` en `includes/modules/`. Los activos se guardan en la opción `karmcp_active_modules` y arrancan en `init` (prioridad 5).

Módulos presentes: Themer, Redirects, Prompts, Brand Kits, Templates, Agent Skills, Cloud, Image Optimization, SVG Support, Guardrails, Login Guard, Known Vulnerabilities.

> **Patrón de gating a respetar:** las abilities se registran en `wp_abilities_api_init`, que corre **antes** de que el módulo arranque en `init:5`. Por eso el registrar consulta el estático `is_enabled()` del módulo, nunca su instancia.

### Modo compacto (dispatcher)

Opción `karmcp_dispatcher_mode` (pestaña Tools, por defecto OFF). Encendida, el servidor expone solo 3 meta-herramientas — `list-tools`, `get-tool-schema`, `call-tool` — en vez de ~130, para clientes con tope de herramientas. `call-tool` **delega en el `check_permissions()` de cada ability destino**: no hay escalada de privilegios, y los toggles por herramienta siguen mandando.

## Lo que NO existe en este árbol

Esto ahorra horas: hay guards por todo el código que comprueban clases que **nunca están presentes**. No son bugs, son restos de una separación de tiers que se eliminó. No intentes "arreglarlos" cargando algo.

- **No hay `pro/`**, ni submódulo, ni `pro-manifest.txt`, ni `KarMCP_Pro_Loader`. Un solo tier.
- **No hay Freemius ni licenciamiento.** `KarMCP_License` y `karmcp_fs()` no existen (0 ocurrencias).
- **No hay auto-updater.** La cabecera lleva `Update URI: false`; se actualiza sustituyendo la carpeta.
- **Clases ausentes** que sus guards siempre resuelven a falso: `KarMCP_Migrate_Abilities`, `KarMCP_Block_Store`, `KarMCP_Widget_Generator`, y los grupos GeneratePress / Blocksy / EssentialAddons / PremiumAddons / UAE / Block Builder / System Kit / SEO / A11y / Widget Builder / Memory. **Skills y Woo ya no están en esta lista**: Skills se implementó en `includes/skills/` y `KarMCP_Woo_Integration` en `includes/abilities/woo/` (catálogo de productos, no pedidos).
- **Guards que devuelven `false` literal:** `KarMCP_Widget_Loader::has_access()`, `KarMCP_Widget_Store::user_has_access()`.
- **Cloud está inerte:** `KarMCP_Cloud::DEFAULT_BASE_URL` está vacío a propósito, así que el plugin **no hace ninguna llamada saliente** salvo que se configure (`KARMCP_CLOUD_URL`, la opción `karmcp_cloud_base_url`, o el filtro homónimo).

## Seguridad: el modelo

Toda herramienta comprueba una capacidad real de WordPress antes de actuar — un agente solo puede hacer lo que su usuario podría hacer a mano.

- Lo que escribe, borra o afecta a todo el sitio **se entrega deshabilitado** y se activa desde **KarMCP → Tools**.
- Lo destructivo exige además `confirm: true`.
- Los administradores no son editables por MCP y no hay herramienta de borrado de usuarios.
- El acceso a ficheros está confinado a `ABSPATH`, con backup automático y log de auditoría; `wp-config.php` y `.htaccess` se rechazan.
- Los snippets PHP **nunca se ejecutan sin aprobación humana**: el agente crea borradores validados, pero solo un admin puede activarlos.

Por encima de eso está el **módulo Guardrails** (`includes/modules/guardrails/`), que es política del dueño del sitio, no seguridad: modo solo lectura, bloqueo de herramientas destructivas, ventana de congelación horaria, posts y tipos de contenido protegidos. La lógica vive en `KarMCP_Guardrails_Policy`, **deliberadamente pura** —ni `get_option()` ni `current_time()` dentro—, y por eso se testea sin WordPress; el módulo reúne los datos y se los pasa.

Se apoya en dos costuras a la vez, y el emparejamiento es el diseño: `karmcp_discovery_memory` publica las reglas en el contexto del agente (prevención) y `karmcp_before_write` las aplica (cumplimiento).

Al lado están las **Skills** (`includes/skills/`), que son lo contrario: no lo que el agente no puede hacer, sino **cómo se hacen aquí las cosas**. Un CPT `karmcp_skill` mapeado sobre campos nativos —título = nombre, `post_name` = nombre de máquina, `post_excerpt` = resumen, `post_content` = cuerpo, `publish`/`draft` = encendido/apagado—, así que no hay meta propia y salen gratis el editor, las revisiones y el buscador. Edición solo para administradores: una skill dirige el comportamiento de los agentes en todo el sitio, está más cerca de la configuración que del contenido.

> **La economía de las dos costuras:** por `karmcp_discovery_memory` va la política **entera**, porque es corta y aplica a todas las llamadas. Por `karmcp_discovery_skills` va **solo el índice** (nombre + resumen); el cuerpo se pide con `get-skill` cuando hace falta. Mandar los cuerpos en cada conexión gravaría todas las conversaciones con guías que la mayoría no usa. Hay un test que fija que el índice nunca lleve cuerpo, porque es una regresión que no rompe nada visible — solo cuesta dinero en silencio.

> **Trampa del modo dispatcher:** `karmcp/call-tool` no está anotada como read-only, así que el veto se dispara **primero para el sobre y después para la herramienta real**. Juzgar el sobre bloquearía lecturas —un `list-posts` invocado vía `call-tool` moriría en una regla de escritura—, así que el módulo lo deja pasar a propósito: la ability destino corre por el mismo callback envuelto y la política la ve con su nombre y sus argumentos verdaderos.

Al añadir una herramienta que escribe: súbele `DEFAULTS_VERSION` en `class-admin.php` y siembra su slug como deshabilitada.

## Trampas verificadas

- **`post_type` tiene un límite duro de 20 caracteres** (`wp_posts.post_type` es `varchar(20)`). El prefijo `karmcp_` es largo; un CPT que se pase **no se registra y falla en silencio**. Por eso el CPT del Themer es `karmcp_theme_tpl`, no `karmcp_theme_template`.
- **Nunca adivines nombres de campos o controles.** Elementor y Spectra aceptan cualquier clave que les mandes sin quejarse, así que un nombre erróneo parece funcionar y no hace nada. Léelo del plugin.
- **Nunca escribas firmas de malware íntegras en disco** (ni en tests). Los escáneres del hosting ponen el archivo en cuarentena, lo cual lo **deja a cero sin borrarlo**: el `require_once` tiene éxito, la clase nunca se declara, y el fatal aparece lejos del origen. Por eso `class-security-malware-audit.php` ensambla sus patrones desde fragmentos en tiempo de ejecución.
- **`render.php` de un bloque debe hacer `echo`, no `return`** — WordPress lo envuelve en su propio buffer e ignora el retorno.

## Deuda conocida

Auditado el 2026-08-14. **La 1.2.0 fue una release de auditoría y vació la mitad de esta lista**: capacidades reales por post type en `create-post`/`update-post`, `read_post` en `get-post`, filtrado por tipo y `perm: readable` en `list-posts`, revalidación de cada salto en `safe_download()`, rate-limit y normalización de `scope` en OAuth, desinstalador completo, `load_plugin_textdomain()`, el falso positivo de `REPLACE()` y el código muerto de Memory. Lo de abajo es lo que sigue pendiente. **`duplicate-post` salió de esta lista en 1.1.0**: vive en `includes/class-post-duplicator.php` (la primitiva) y `includes/abilities/class-duplicate-abilities.php` (la herramienta), y es también sobre lo que se construye `create-translation`.

Fuera de esta lista, sin abordar y verificado el 2026-08-14: **no hay CI** (`.github/` solo tiene plantillas de issues) y **`languages/` sigue vacío** — el cargador ya está, pero no hay `.pot` ni traducciones.

- **`includes/admin/class-admin.php` son ~5.800 líneas** mezclando 6 responsabilidades. `get_tool_catalog()` es un único método de ~1.840 líneas que solo devuelve un array. La extracción natural es a archivos de datos, patrón que el repo ya usa en `includes/widgets/catalog-*.php`.
- **Duplicación en abilities:** 168 registros repiten el literal completo; solo `class-database-abilities.php` y `class-wpcli-abilities.php` lo factorizan en un helper `ability()`. Esa es la plantilla a seguir.
- **Los `scope` OAuth se normalizan al emitirlos pero nadie los lee.** `KarMCP_OAuth_Bearer::permission_callback()` autentica el token y no mira su columna `scopes`. Hoy da igual —solo existe el scope `mcp`—, pero el día que haya un segundo scope, ese callback es donde hay que aplicarlo.
- **El desinstalador conserva a propósito el contenido de los CPT** (brand kits, plantillas del Themer, Skills). Es una decisión, no un olvido: son cosas que escribió una persona. Está documentada en `class-uninstaller.php` para que no se re-litigue.

## Documentos

| Archivo | Qué es |
|---|---|
| [docs/ROADMAP-SEO-A11Y-THEMER.md](docs/ROADMAP-SEO-A11Y-THEMER.md) | Plan real de las dos capacidades propias. Themer extendido: **hecho**. SEO y accesibilidad: pendiente, con los seams ya verificados. |
| [docs/ROADMAP-SECURITY.md](docs/ROADMAP-SECURITY.md) | El apartado de Seguridad, para sustituir a Wordfence: CI, pestaña Security, `harden-site`, drop-in de fatales, `update-core`, módulo de vulnerabilidades y parcheo. Escrito para implementarse desde cero. |
| [docs/MAINTENANCE-UPSTREAM.md](docs/MAINTENANCE-UPSTREAM.md) | Nota interna de mantenimiento: qué se ha revisado del árbol de origen y qué divergencias no deben reimportarse. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Cómo añadir una herramienta o una integración. |
| `NOTICE` / `LICENSE` | Atribución y licencia. **No tocar.** |
