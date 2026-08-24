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
| Versión actual | `1.34.0` — en `karmcp.php` (cabecera + `KARMCP_VERSION`) y `readme.txt` (`Stable tag`); los tres tienen que coincidir |

**Los `@since` de 2.x y 3.x del código no son releases de KarMCP.** Vienen del árbol del que deriva y se dejaron como están: reescribirlos en masa falsearía más de lo que aclara. La numeración de KarMCP empieza en 1.0.0, así que **cualquier `@since` nuevo se escribe con la versión actual**.

Estándares de WordPress, estrictos: `snake_case` en funciones y variables, `Upper_Snake_Case` en clases, sanitizar entrada, escapar salida, `$wpdb->prepare()`, nonces y capacidades siempre. Código y comentarios **en inglés**; la documentación de proyecto (este archivo, el roadmap) en español.

## Tests y análisis estático

**Un solo comando corre las tres cosas:**

```powershell
pwsh bin/check.ps1
```

PHPUnit + comprobación de frescura del POT + PHPStan + PHPCS, las cuatro bloqueantes. Si falta la cadena de análisis, la instala. `-Quick` se salta PHPCS, que es el lento (~45 s).

Estado de referencia (1.27.0): **1.147 tests, 8.955 aserciones**; **PHPStan sin errores**; **PHPCS sin errores ni avisos**. Las tres bloquean. Cualquier hallazgo que veas lo ha introducido lo que estés cambiando.

### El entorno, montado en la 1.16.2

En esta máquina (Windows, PHP 8.5.5 de winget) faltaba todo esto y **el resultado fue que durante meses solo corría la suite**: `phpcs.xml.dist` y `phpstan.neon.dist` llevaban tiempo escritos y no se habían ejecutado nunca. Ya está resuelto y no hay que repetirlo:

- `mbstring` y `fileinfo` **activadas en el `php.ini`** de winget (había copia de seguridad `.bak-20260817`). Ya no hacen falta los `-d extension=…` que documentaba este archivo.
- `composer.phar` en `C:\Desarrollos\MCPKar\` (hash verificado contra el `.sha256sum` oficial), junto a `phpunit-10.phar`. No hay Composer en el PATH y no hace falta: `php C:\Desarrollos\MCPKar\composer.phar --working-dir=tools update`.
- La cadena vive en `tools/` con su propio `composer.json`, y ni `tools/vendor/` ni el lock se comitean.

> **PHPStan tuvo que subir a 2.x.** La 1.12 es de julio de 2025 y **no parsea PHP 8.5**: revienta en los stubs internos al llegar a la nueva función `clone()`, con un "Internal error" que no parece un problema de versión. Si vuelve a aparecer, es eso.

> **`tools/phpstan-bootstrap.php` definía `KARMCP_DIR` como `__DIR__`**, que es `tools/`, no la raíz. Cada `require_once KARMCP_DIR . 'includes/…'` se resolvía a una ruta inexistente: **241 errores inventados solo en `class-bootstrap.php`**, suficiente ruido para enterrar cualquier cosa real. Corregido a `dirname( __DIR__ )`.

Los 95 hallazgos que quedaban están congelados en `phpstan-baseline.neon` — casi todos son llamadas a software que puede no estar instalado (ACF, WooCommerce, CF7, Polylang, la clase base de widgets de Elementor). **Lo que PHPStan reporte a partir de ahora es nuevo.** Al limpiar un grupo, se regenera:

```bash
php tools/vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon
```

**PHPCS no tiene baseline a propósito, y desde la 1.16.3 no lo necesita: está a cero y bloquea.** El ruleset es estrecho (inyección, salida sin escapar, nonces, SQL preparado, i18n, prefijos globales, compatibilidad con PHP 8.1). El siguiente bloque del trinquete es Docs.

> **Los nombres de tabla van con `%i`, no concatenados.** `$wpdb->prepare( 'SELECT … FROM %i …', self::table(), … )`. Es el placeholder de identificadores de WordPress 6.2+, y aquí el mínimo es 6.9. Concatenar el nombre funciona pero no se puede verificar, y en los dos sitios donde la tabla **viene del llamante** (`describe-table` y el before-image del guard de base de datos) el escapado lo hacía un `str_replace` casero. No vuelvas a concatenar.

> **Cuando el arreglo obvio es el equivocado, el motivo va en el código.** Un `redirect_uri` de OAuth tiene que salir del sitio —`wp_safe_redirect()` rompería el login entero—, la cabecera `Authorization` no se sanea porque `parse_bearer()` ya la restringe al alfabeto base64url, el código de una plantilla PHP no se puede sanear sin destruirlo, y los hooks de terceros (WPML, el adapter, el `the_content` de core) no se pueden prefijar sin dejar de ser esos hooks. Todos llevan su `phpcs:ignore` **con la razón escrita al lado**. Si te encuentras uno, léelo antes de "arreglarlo".

### CI: las mismas puertas, en GitHub Actions

Dos workflows en `.github/workflows/`, en cada push y PR, **los dos bloqueantes**:

- **`tests.yml`** — la suite en PHP 8.1 (el mínimo de la cabecera) a 8.5 (la máquina de desarrollo). Sin `composer install` a propósito: el bootstrap de tests carga con `require_once` y no depende de `vendor/`.
- **`lint.yml`** — un job con PHPCS y PHPStan (con su baseline y el mismo `--memory-limit=2G` que `check.ps1`), y otro sin composer con la frescura del classmap y del POT.

**La regla de paridad: si `bin/check.ps1` gana una puerta, el workflow que le toque la gana también.** La historia que la justifica: `lint.yml` nació (Pieza 0, 2026-08-15) con `continue-on-error` y un plan escrito — "quitarlos cuando el ruido esté triado". El triaje terminó dos días después (1.16.3) y nadie volvió al archivo: el check salía verde sin poder fallar, mientras este documento seguía diciendo "no hay CI". Un verde que no puede fallar y una doc que no se relee fallan igual: en silencio. Se cerró el 2026-08-23.

### La suite

Corre sobre stubs, sin instalar WordPress. Los tests viven en `tests/`, nombrados `AlgoTest.php`, y prueban lógica pura (validadores, mapeo de esquemas, enrutado de dispatchers, delegación de permisos). Lo que toca el render real del front-end necesita verificación manual en un WordPress local.

El harness comparte stubs en `tests/bootstrap.php`, y ahí está la trampa: **un stub del harness gana al que declare un fichero de test**, porque el bootstrap carga primero. Si añades ahí una función que un test ya simulaba por su cuenta, ese test empieza a leer una fixture distinta y falla lejos del cambio. Pasó con `wp_get_object_terms()` y los menús.

## Arquitectura

### Flujo de registro

Tres hooks, en este orden:

1. `wp_abilities_api_categories_init` → registra la categoría `karmcp`
2. `wp_abilities_api_init` → registra las abilities vía `karmcp_register_ability()` (envoltorio en `includes/class-schema-compat.php:304`)
3. `mcp_adapter_init` → crea el servidor MCP con la lista de abilities

> **Trampa cara:** si omites `'category' => 'karmcp'` al registrar, `wp_register_ability()` **descarta la ability en silencio**. Sin error, sin aviso: la herramienta simplemente no existe.

### Cada petición carga lo que usa (1.30.0)

**No hay lista de `require_once`.** `KarMCP_Autoloader` (`includes/class-autoloader.php`) resuelve cada clase `KarMCP_*` la primera vez que alguien la nombra, contra el mapa generado `includes/classmap.php`. Añadir un archivo de clase **no requiere tocar el bootstrap**: se regenera el mapa y ya está.

```bash
php bin/generate-classmap.php          # reescribe includes/classmap.php
php bin/generate-classmap.php --check  # falla si está desfasado (lo corre bin/check.ps1)
```

Medido sobre una visita anónima: **1.548 KB en 157 archivos pasaron a 402 KB en 43**. Lo que queda es lo que `wire_hooks()` toca de verdad — el registro de módulos, los stores cuyos CPT se registran en `init`, los loaders, el runtime de hardening y la barra de admin.

**El orden de carga ya no es un problema.** El trait antes de las integraciones, cada base abstracta antes de sus subclases, el store y su loader juntos: PHP resuelve el padre al declarar el hijo, así que el autoloader lo acierta por construcción. Esas reglas se mantenían a mano en el orden de la lista y una línea mal puesta era un fatal inmediato.

**Lo que el autoloader no alcanza son las funciones globales.** PHP autocarga clases, nunca funciones, así que los dos archivos que declaran una junto a su clase siguen con `require_once` en `load_classes()`:

| Archivo | Función | Por qué no puede esperar |
|---|---|---|
| `includes/class-schema-compat.php` | `karmcp_register_ability()` | La llama cada grupo de abilities al registrarse. |
| `includes/themer/class-themer-render-controller.php` | `karmcp_themer_location()` | Un tema sin soporte la llama desde su `header.php`, antes de que nada nombre la clase. |

**Si escribes un tercero, `ClassmapTest` falla y te lo dice.** Ese test fija las tres propiedades sobre las que se sostiene todo esto: el mapa cuadra con el árbol, **ningún archivo de clase hace trabajo al incluirse**, y solo esos dos declaran función global. La segunda es la de verdad importante — los hooks se enganchan por nombre en `wire_hooks()`, y un `add_action()` en el nivel superior de un archivo dejaría de ejecutarse en cuanto nada más nombrara esa clase, sin error en ninguna parte.

### Las clases de herramientas se cargan bajo demanda (1.16.2)

Por encima del autoloader hay un segundo diferido, anterior y todavía vigente. Los **76 archivos de `includes/abilities/`** los carga `KarMCP_Bootstrap::load_ability_classes()` — idempotente, pública— y solo cuando alguien pide una herramienta: `wp_abilities_api_init` (que es **perezoso**: dispara en la primera llamada a `wp_get_ability()`), `mcp_adapter_init`, y wp-admin. Una visita al front que no toca ninguna herramienta no parsea 1,2 MB de PHP.

**Si añades un archivo de abilities, va en `load_ability_classes()`.** Esa lista sigue siendo explícita a propósito: no está ahí para poder cargar las clases, sino para cargarlas **todas de golpe** en el momento en que alguien pide una herramienta. El orden dentro de ella ya no importa — lo arregla el autoloader —, pero la lista sí, porque es lo que mantiene medible el coste del registro MCP.

> **`is_admin()` también es cierto en `admin-ajax.php`**, así que el diferido se caía por ahí: el latido del editor y cualquier llamada AJAX de otro plugin cargaban las pantallas de admin y toda la capa de herramientas. Desde la 1.26.0 `boot()` pasa por `admin_context_needs_tools()`, que en una petición AJAX solo deja pasar las acciones `karmcp_`. **Si registras un handler de admin-ajax, tiene que llevar ese prefijo** o no se cargará la clase que lo atiende. `admin-post.php` no es AJAX y no está afectado.

> **Lo que se carga en cada petición está medido**, no estimado: [docs/AUDIT-RENDIMIENTO-PLUGIN.md](docs/AUDIT-RENDIMIENTO-PLUGIN.md) tiene las cifras por ruta y el método para reproducirlas. Los dos cambios estructurales que pedía se hicieron en la 1.30.0: el autoloader por classmap (Hallazgo 1) y la puerta de ruta del servidor MCP (Hallazgo 2). Quedan abiertos el índice autocargado de redirecciones (Hallazgo 4) y el log MCP (Hallazgo 7).

### La puerta de ruta del servidor MCP (1.30.0)

`rest_api_init` dispara en **toda** petición REST, no solo en la nuestra, y montar el servidor cuesta la capa de herramientas entera. `KarMCP_Plugin::request_needs_mcp_server()` lo corta: solo se monta bajo el namespace `mcp/`, en el índice `/wp-json/` y en WP-CLI. Medido sobre `/wp-json/wp/v2/posts`: **3.020 KB en 238 archivos → 402 KB en 43**.

> **Declinar nuestro servidor no basta, y esto es lo que la auditoría había diagnosticado mal.** El adapter monta **su** servidor por defecto en `mcp_adapter_init` con prioridad 10 —antes que el nuestro, que va en la 20— y al hacerlo llama dos veces a `wp_get_abilities()` para descubrir recursos y prompts. Eso fuerza la API de abilities perezosa, que dispara nuestro `register_abilities()`, que carga las 76 clases y registra ~200 abilities. La petición ya había pagado entera antes de llegar a nuestro hook. Por eso hay un filtro sobre `mcp_adapter_create_default_server`, y sin él el cambio no ahorra nada.

> **La puerta prueba el namespace, no nuestra ruta.** El servidor por defecto del adapter vive en `/mcp/mcp-adapter-default-server`, al lado del nuestro. Afinar la comprobación a `/mcp/karmcp-server` lo convertiría en un 404 para quien lo use.

> **Tampoco se puede montar el servidor solo con los nombres**, que era la salida que proponía la auditoría: `McpComponentRegistry::register_ability_tool()` resuelve cada ability con `wp_get_ability()` al construir. Un servidor hecho con nombres sin registrar expone cero herramientas y escribe una línea de log por cada uno.

El escape es el filtro `karmcp_needs_mcp_server`, para un cliente que llegue por una ruta que esto no reconozca.

> **La regla que lo sostiene:** ningún archivo fuera de `includes/abilities/` puede depender de una clase de ahí en tiempo de carga. Hay seis referencias permitidas, cada una con su motivo, y `DeferredAbilityLoadTest` las fija. Si añades una séptima el test te dice qué hacer: quitarla, llamar a `load_ability_classes()` antes, o justificarla en `ALLOWED`. Sin ese test, romperlo es un fatal en producción que la suite no ve.

### Capas

1. **Datos** (`class-elementor-data.php`) — envoltorio de lectura/escritura sobre `_elementor_data`. **Nunca escribas ese meta directamente**: todo guardado pasa por `\Elementor\Plugin::$instance->documents->get()->save()`, que es lo que regenera el CSS e invalida caché. Una escritura cruda produce bugs que solo se ven en el front.
2. **Factory** (`class-element-factory.php`) — construye JSON válido de Elementor; cada elemento recibe un id hex de 7 caracteres.
3. **Esquemas** (`schemas/`) — genera JSON Schema a partir de los controles reales de Elementor. No se escriben a mano.
4. **Abilities** (`includes/abilities/`, 66 archivos) — las herramientas, agrupadas por dominio y coordinadas por `class-ability-registrar.php`.

### El bucle cerrado: render-page

`KarMCP_Content_Extractor` (`includes/class-content-extractor.php`) renderiza un post como lo recibe un visitante y lo reduce a un digest normalizado. Está partido a propósito: `analyze()` es **puro** (HTML entra, digest sale, sin WordPress) y es lo que se testea; `extract()` es la mitad que resuelve un post a HTML. No reinventa nada: el render delega en `KarMCP_Themer_Content_Renderer::render()` y el `scope: full` en `KarMCP_Performance_Page_Audit::fetch()`, que ya revalida cada salto de redirección contra el host de origen.

Es la base compartida que pedía el roadmap de SEO y accesibilidad. **`audit-page-seo` ya la consume** (`includes/audits/class-seo-audit.php`); `audit-page-a11y` está pendiente y consumirá la misma vista.

### Las auditorías: reglas puras sobre el digest

`KarMCP_Seo_Audit::run( $digest, $ctx )` es **pura**: no llama a WordPress, no consulta nada. Todo lo que una regla no puede ver del digest entra por `$ctx`, que reúne el llamante. Es lo que permite que las 56 pruebas de esta parte corran sin WordPress, y la propiedad que hay que conservar al añadir reglas.

Dos piezas alrededor:

- `KarMCP_Audit_Score` (`includes/audits/class-audit-score.php`) — pesos, nota y recuento. Separado **para que la auditoría de accesibilidad gradúe en la misma curva**; dos auditorías con su propio 0-100 dan números que nadie puede comparar.
- `KarMCP_Seo_Meta` (`includes/class-seo-meta.php`) — un solo vocabulario sobre Yoast, Rank Math y Slim SEO. Reutiliza los nombres de campo que ya usaba la integración de Slim SEO; no inventes un segundo.

> **Intención guardada contra resultado renderizado.** `KarMCP_Seo_Meta` dice lo que hay *guardado*; el digest dice lo que *sale*. La auditoría compara los dos, y ahí es donde aparecen los hallazgos que importan: una plantilla `%%title%%` que expande a nada, una descripción que el tema nunca emite. Un valor con variables sin expandir **se reporta como plantilla, no se mide**: medir su longitud sería graduar la plantilla en vez de lo que lee el visitante.

> **Las longitudes se cuentan en caracteres, no en bytes.** Cada acento de un título en español ocupa dos bytes; contar bytes suspendería un título que se ve perfecto. Hay un test que lo fija con un fixture que cabe en caracteres y no en bytes.

> **AIOSEO y SEOPress se detectan pero no se leen**, a propósito: guardan en tablas propias. Adivinar un esquema ajeno produce un lector que devuelve cadenas vacías para siempre sin fallar nunca. El seam es el filtro `karmcp_seo_meta`.

### Integraciones de plugin: el trait

Las integraciones exponen dos herramientas (`<id>-read` / `<id>-write`) que reciben `{ operation, arguments }`. La mecánica común —resolver la operación, revalidar su capacidad, exigir `confirm` en las destructivas, devolver el catálogo cuando no se nombra ninguna— vive en `includes/abilities/trait-operation-dispatcher.php`. La base de formularios es anterior y conserva su copia; **lo nuevo usa el trait**.

### Widgets: catálogo, no herramientas

Los widgets son **datos** en `includes/widgets/` (`catalog-{free,pro,woo}.php`), servidos por `KarMCP_Widget_Catalog`. El flujo es **descubrir → inspeccionar → actuar**: `list-widgets` → `get-widget-schema` → `add-free-widget` / `add-pro-widget` → `update-widget`. Cualquier control válido de Elementor pasa aunque no esté en el catálogo.

### El sandbox: compilar, no ejecutar lo que escribe el agente

Cuatro artefactos viven en `wp-content/karmcp-sandbox/` (`KarMCP_Sandbox_Paths`): snippets PHP, widgets de Elementor, bloques de Gutenberg y **extensiones de elemento**. Los tres últimos los **compila el plugin** desde un spec; el agente no escribe PHP en ningún momento, y eso es la línea que no se cruza.

El spec es datos: metadatos, campos tipados y una plantilla HTML con `{{marcadores}}`. `KarMCP_Sandbox_Template` la convierte en PHP y ahí está la propiedad clave: **todo lo que no es un marcador se emite con `var_export()` como literal de cadena**, así que el texto de la plantilla no puede volverse código aunque lo parezca. El escape de cada marcador lo decide el **tipo declarado** del campo, no quien escribió el spec, y no existe modificador `raw`. El compilador no elige el escape: se lo pregunta a su llamante (`KarMCP_Widget_Generator` / `KarMCP_Block_Generator`), que es quien conoce la forma de los valores de su plataforma.

| Pieza | Widgets | Bloques | Extensiones |
|---|---|---|---|
| Vocabulario + validación | `KarMCP_Widget_Spec` | `KarMCP_Block_Spec` | `KarMCP_Extension_Spec` |
| Compilador (puro) | `KarMCP_Widget_Generator` | `KarMCP_Block_Generator` | `KarMCP_Extension_Generator` |
| Almacén | `KarMCP_Widget_Store` | `KarMCP_Block_Store` | `KarMCP_Extension_Store` (los dos últimos sobre `KarMCP_Sandbox_Store`) |
| Carga | `KarMCP_Widget_Loader` | `KarMCP_Block_Loader` | `KarMCP_Extension_Loader` |
| Herramientas MCP | `KarMCP_Widget_Builder_Abilities` | `KarMCP_Block_Builder_Abilities` | `KarMCP_Extension_Builder_Abilities` |

**Las extensiones son distintas de las otras dos** y conviene tenerlo claro antes de tocarlas: un widget o un bloque se insertan; una extensión **modifica elementos que ya existen**, añadiendo una sección al panel de los contenedores de Elementor 4.2+. Eso la obliga a escribir en un espacio de nombres ajeno, y de ahí sus dos reglas: las props llevan prefijo `karmcp_` (el schema de props es un array compartido con Elementor y con Pro, y una colisión tapa la prop del otro en silencio; el store rechaza activar dos extensiones que declaren el mismo nombre) y la salida solo puede escribir atributos `data-` y `aria-`. Las costuras que usa y las que no sirven están en [docs/ROADMAP-ELEMENT-EXTENSIONS.md](docs/ROADMAP-ELEMENT-EXTENSIONS.md).

> **`add_render_attribute( '_wrapper', … )` no funciona en elementos atómicos con plantilla.** Su `before_render()` está vacío a propósito y el HTML sale de Twig, que lee `settings.classes` y `settings.attributes`. El generador muta esas props (y escribe el wrapper igualmente, por si el elemento no usa plantilla). Costó la 1.13.1 descubrirlo: el síntoma es que el hook corre —los assets se encolan— y aun así el atributo no aparece.

> **La trampa del default, que solo aparece al ejecutarlo:** las props se declaran en *todos* los elementos, y casi ninguno tendrá nada guardado. Si un valor ausente se leyera como cadena vacía, una regla `not: "none"` sería cierta en todas partes y el efecto caería sobre cada contenedor del sitio. El código generado cae al **default declarado** de la prop, no a `''`.

Los generadores son **puros a propósito** (arrays entran, strings salen, sin WordPress): es lo que permite que `validate-widget-spec` haga un dry-run del compilador de verdad y no de una aproximación, y que los tests ejecuten el código generado con `eval` para comprobar el escapado contra payloads hostiles. Si alguna vez hay que tocarlos, esa pureza es el activo.

Los bloques se renderizan en servidor: un único script de editor genérico (`assets/js/sandbox-blocks.js`, sin build step) los registra desde el payload del manifest y previsualiza con `ServerSideRender`. El patrón viene de `KarMCP_Themer_Blocks`, que ya lo hacía.

> **Trampa del bootstrap:** `boot()` instancia `KarMCP_Block_Loader` en cuanto existe `KarMCP_Block_Store`. Las dos clases se cargan juntas o el sitio fatalea en cada request.

> **El descriptor del editor se hornea en el manifest** durante `rebuild_manifest()`, no se lee de la base de datos en cada carga. El manifest existe justamente para que renderizar una página no consulte nada.

### Módulos

Features que el admin enciende y apaga desde la pestaña **Modules**. Base `KarMCP_Module` + `KarMCP_Modules_Registry` en `includes/modules/`. Los activos se guardan en la opción `karmcp_active_modules` y arrancan en `init` (prioridad 5).

Módulos presentes: Themer, Redirects, Agent Skills, Image Optimization, SVG Support, Guardrails, Login Guard, Known Vulnerabilities.

> **Patrón de gating a respetar:** las abilities se registran en `wp_abilities_api_init`, que corre **antes** de que el módulo arranque en `init:5`. Por eso el registrar consulta el estático `is_enabled()` del módulo, nunca su instancia.

### Modo compacto (dispatcher)

Opción `karmcp_dispatcher_mode` (pestaña Tools, por defecto OFF). Encendida, el servidor expone solo 3 meta-herramientas — `list-tools`, `get-tool-schema`, `call-tool` — en vez de ~130, para clientes con tope de herramientas. `call-tool` **delega en el `check_permissions()` de cada ability destino**: no hay escalada de privilegios, y los toggles por herramienta siguen mandando.

### Traducciones (1.27.0)

`languages/` lleva el POT, el `.po` y el `.mo` de español, y **viaja en el zip** (no está en `.gitattributes` como `export-ignore`). El text domain se carga desde siempre en `KarMCP_Bootstrap::load_textdomain()`; lo que faltaba era el material.

Ni WP-CLI ni las utilidades de GNU gettext están en esta máquina, así que los tres pasos son PHP autocontenido en `tools/` (que sí es `export-ignore`, y queda fuera de PHPStan y PHPCS):

```bash
php tools/make-pot.php          # extrae; --check falla si el POT está viejo
php tools/make-po.php es_ES     # lo que hace msgmerge
php tools/make-mo.php es_ES     # lo que hace msgfmt
```

La extracción va con `token_get_all()`, no con regex: resuelve `'a' . 'b'`, las dos comillas y sus escapes, y nunca captura una llamada que esté dentro de un comentario o de una cadena. `tools/lib-po.php` es el formato compartido por los tres, y la clave de una entrada es exactamente la de gettext (contexto, `\4`, singular) — cambiar eso pierde traducciones en el siguiente merge, en silencio.

> **Las 1.192 cadenas de `includes/abilities/` se quedan en inglés a propósito.** Son el `label` y la `description` de cada herramienta MCP: viajan en el esquema que lee el agente y **no las muestra el admin**, que renderiza su propio catálogo curado desde `class-admin.php:3860`. Son instrucciones operativas afinadas contra el comportamiento real de un agente; traducirlas cambiaría lo que se le dice, solo en sitios en español, sin que nadie vuelva a probarlo. Con el `msgstr` vacío gettext devuelve el original, que es justo lo que se quiere. `make-pot.php` les pone la nota `DO NOT TRANSLATE` a cada una, y `TranslationFilesTest` falla si alguien las rellena.

`bin/check.ps1` corre `make-pot.php --check` porque **la suite no puede saber si el POT sigue cuadrando con el código**: un `__()` nuevo simplemente no llegaría nunca a un traductor. Lo demás sí lo fija `TranslationFilesTest`: el `.po` contiene exactamente lo que el POT, no falta ninguna cadena visible, los plurales están completos, los placeholders de `printf` sobreviven a la traducción, y el `.mo` es de verdad la compilación del `.po` de al lado — releído por una **segunda** implementación del formato binario, para que un fallo del escritor no se dé la razón a sí mismo.

## Lo que NO existe en este árbol

Esto ahorra horas: hay guards por todo el código que comprueban clases que **nunca están presentes**. No son bugs, son restos de una separación de tiers que se eliminó. No intentes "arreglarlos" cargando algo.

- **No hay `pro/`**, ni submódulo, ni `pro-manifest.txt`, ni `KarMCP_Pro_Loader`. Un solo tier.
- **No hay Freemius ni licenciamiento.** `KarMCP_License` y `karmcp_fs()` no existen (0 ocurrencias).
- **No hay auto-updater.** La cabecera lleva `Update URI: false`; se actualiza sustituyendo la carpeta.
- **Clases ausentes** que sus guards siempre resuelven a falso: `KarMCP_Migrate_Abilities` y los grupos GeneratePress / Blocksy / EssentialAddons / PremiumAddons / UAE / System Kit / SEO / A11y / Memory. **Skills, Woo y los dos builders del sandbox ya no están en esta lista**: Skills vive en `includes/skills/`, `KarMCP_Woo_Integration` en `includes/abilities/woo/`, y el Widget/Block Builder se implementó en 1.12.0 (ver abajo).
- **Cloud ya no existe.** El subsistema (cliente OAuth, sync, gateway credential, sus 5 herramientas MCP, el backup del Sandbox y las notificaciones que se alimentaban de él) se eliminó en 1.25.0: apuntaba a un servicio que nunca existió.

## Seguridad: el modelo

Toda herramienta comprueba una capacidad real de WordPress antes de actuar — un agente solo puede hacer lo que su usuario podría hacer a mano.

- Lo que escribe, borra o afecta a todo el sitio **se entrega deshabilitado** y se activa desde **KarMCP → Tools**.
- Lo destructivo exige además `confirm: true`.
- Los administradores no son editables por MCP y no hay herramienta de borrado de usuarios.
- El acceso a ficheros está confinado a `ABSPATH`, con backup automático y log de auditoría; `wp-config.php` y `.htaccess` se rechazan.
- Los snippets PHP **nunca se ejecutan sin aprobación humana**: el agente crea borradores validados, pero solo un admin puede activarlos.

### El guard de SQL: tokens, no texto (1.34.0)

`query` es la única herramienta que acepta SQL crudo, y hasta la 1.34.0 lo validaba normalizando la sentencia a una cadena y pasándole expresiones regulares. Ese diseño solo vale lo que valga la paridad del normalizador con MySQL, y una auditoría del árbol de origen encontró **cuatro sitios donde discrepaban** — `--` sin espacio detrás, la barra invertida bajo `NO_BACKSLASH_ESCAPES`, los backticks quitados antes de lexar, y las comillas dobles bajo `ANSI_QUOTES`. Tres de las cuatro dejaban leer la tabla de usuarios, y dos las introdujo el arreglo de otra. El síntoma siempre era el mismo: el escáner leía un byte distinto del servidor y producía una cadena con buena pinta.

Ahora hay dos clases puras en `includes/sql/`:

- `KarMCP_SQL_Lexer` — parte la sentencia en tokens tipados y **da cuenta de cada byte**. Lo que no sabe clasificar, o no puede terminar, es un error duro, no una conjetura.
- `KarMCP_SQL_Policy` — las reglas, sobre tokens. Una palabra dentro de un literal es un literal; un identificador lo es lleve lo que lleve dentro.

> **Se analiza bajo los cuatro modos de sesión a la vez.** `ANSI_QUOTES` y `NO_BACKSLASH_ESCAPES` son los dos únicos modos de MySQL que cambian **cómo se tokeniza**, y una sentencia se acepta solo si es segura bajo *todas* las lecturas. Así el guard nunca tiene que saber el modo real del servidor. Están en `MODE_FLAGS`; si algún día hay un tercero, es el único sitio que tocar. La excepción es `MODE_DEPENDENT_ERRORS`: una comilla sin cerrar bajo un modo es un error de sintaxis en ese modo, así que esa lectura no es una que el servidor pudiera ejecutar y se descarta. Cualquier otro fallo del lexer rechaza la sentencia entera.

> **Cuatro divergencias deliberadas respecto al árbol de origen**, todas verificadas con test. (1) `REPLACE()`, `INSERT()` y `TRUNCATE()` son funciones de solo lectura que comparten grafía con sentencias de escritura; upstream reintrodujo en su reescritura el falso positivo que esta rama arregló en la 1.2.0. Están exentas en `KEYWORD_FUNCTIONS`. (2) La exención exige el paréntesis **pegado**, que es lo que MySQL pide de una función interna fuera de `IGNORE_SPACE`; para **bloquear** una función, en cambio, vale cualquier paréntesis. La asimetría es a propósito: los dos errores caen hacia rechazar. (3) `INTO` sigue en la lista negra — en un `SELECT` solo puede ser `INTO OUTFILE`, `INTO DUMPFILE` o `INTO @var`, y ninguna es una lectura. (4) Los esquemas de sistema se comprueban solo en los **cualificadores** (lo que va antes del punto): `sys` es un nombre de columna plausible, y sin cualificar no se llega a esos datos porque `USE` está prohibido y la segunda sentencia también.

> **`check_read_query()` es la puerta, y es una sola llamada a propósito.** Reúne las tres preguntas que una lectura cruda tiene que pasar: ¿es de solo lectura?, ¿toca un esquema de sistema?, ¿toca una tabla protegida? Las tres solo son correctas juntas, y un segundo camino de SQL crudo que recordara dos de ellas sería una fuga. Los métodos sueltos siguen existiendo para los tests.

> **El tope de filas se impone en la base de datos.** Antes se traían todas las filas y se cortaban en PHP, así que una consulta ancha era un fatal por memoria en vez de una respuesta capada. `bound_sql()` añade el `LIMIT` —en su propia línea, porque un comentario de línea al final se lo tragaría— y **rechaza** un `LIMIT` del llamante que se pase, en vez de reescribirlo: editar SQL crudo alrededor de literales es justo la clase de truco que este guard existe para parar. Va con un timeout de sentencia del servidor (`max_statement_time` en MariaDB, `max_execution_time` en MySQL), restaurado en un `finally`.

Por encima de eso está el **módulo Guardrails** (`includes/modules/guardrails/`), que es política del dueño del sitio, no seguridad: modo solo lectura, bloqueo de herramientas destructivas, ventana de congelación horaria, posts y tipos de contenido protegidos. La lógica vive en `KarMCP_Guardrails_Policy`, **deliberadamente pura** —ni `get_option()` ni `current_time()` dentro—, y por eso se testea sin WordPress; el módulo reúne los datos y se los pasa.

Se apoya en dos costuras a la vez, y el emparejamiento es el diseño: `karmcp_discovery_memory` publica las reglas en el contexto del agente (prevención) y `karmcp_before_write` las aplica (cumplimiento).

Al lado están las **Skills** (`includes/skills/`), que son lo contrario: no lo que el agente no puede hacer, sino **cómo se hacen aquí las cosas**. Un CPT `karmcp_skill` mapeado sobre campos nativos —título = nombre, `post_name` = nombre de máquina, `post_excerpt` = resumen, `post_content` = cuerpo, `publish`/`draft` = encendido/apagado—, así que no hay meta propia y salen gratis el editor, las revisiones y el buscador. Edición solo para administradores: una skill dirige el comportamiento de los agentes en todo el sitio, está más cerca de la configuración que del contenido.

> **La economía de las dos costuras:** por `karmcp_discovery_memory` va la política **entera**, porque es corta y aplica a todas las llamadas. Por `karmcp_discovery_skills` va **solo el índice** (nombre + resumen); el cuerpo se pide con `get-skill` cuando hace falta. Mandar los cuerpos en cada conexión gravaría todas las conversaciones con guías que la mayoría no usa. Hay un test que fija que el índice nunca lleve cuerpo, porque es una regresión que no rompe nada visible — solo cuesta dinero en silencio.

> **Trampa del modo dispatcher:** `karmcp/call-tool` no está anotada como read-only, así que el veto se dispara **primero para el sobre y después para la herramienta real**. Juzgar el sobre bloquearía lecturas —un `list-posts` invocado vía `call-tool` moriría en una regla de escritura—, así que el módulo lo deja pasar a propósito: la ability destino corre por el mismo callback envuelto y la política la ve con su nombre y sus argumentos verdaderos.

Al añadir una herramienta que escribe: súbele `DEFAULTS_VERSION` en `class-admin.php` y siembra su slug como deshabilitada.

## Trampas verificadas

- **Nunca reescribas un archivo del plugin con `Set-Content -Encoding utf8` de PowerShell.** En Windows PowerShell 5.1 eso significa **UTF-8 con BOM**, y un BOM son tres bytes *fuera* de `<?php` que PHP emite como salida. En `karmcp.php` —que se carga en cada petición— eso antepone `EF BB BF` a **todas** las respuestas del sitio: el JSON deja de parsear (`expected value at line 1 column 1`), el servidor MCP se vuelve inalcanzable y los endpoints que emiten sus propias cabeceras dan 404, mientras el sitio sigue cargando con normalidad. Pasó en la 1.16.3 y no lo cazó nada: el archivo sigue siendo PHP válido, así que los tests, PHPCS, PHPStan y `php -l` pasaban todos. Ahora hay `NoByteOrderMarkTest`. Para editar desde PowerShell usa `[IO.File]::WriteAllText($p, $c, (New-Object Text.UTF8Encoding $false))`, o simplemente edita con las herramientas normales.

- **La opción `karmcp_fatal_paused` no se lee cruda: se lee con `KarMCP_Fatal_Handler_Template::paused()`.** El drop-in pausa un plugin sacándolo de `active_plugins` y escribiendo una fila ahí, pero **reactivarlo por cualquier otra vía no borra la fila** — solo lo hacía `resume-plugin`. A partir de ese momento dos herramientas nuestras se contradecían: `list-plugins` lo daba activo y `list-paused-plugins` pausado. No rompe nada, y por eso sobrevivió: el único síntoma es que un agente recibe un dato falso sobre el sitio. Desde la 1.30.1 `paused()` reconcilia contra `active_plugins` (y contra los activados en red, cuyo fichero es la **clave** del mapa, no el valor), y `forget()` borra la fila de verdad enganchado a `activated_plugin`. **`paused()` no escribe a propósito**: `list-paused-plugins` está anotada `readonly`, y un lector que escribe convierte esa anotación en mentira — que es justo lo que leen Guardrails y el modo dispatcher. Si añades un cuarto lector, usa `paused()`.
- **Un valor `html-v3` guarda el mismo texto dos veces y las dos mitades tienen que cuadrar.** `content` lleva el marcado y `children` un árbol de nodos, que es de donde pinta el control de texto enriquecido del editor. Escribir el marcado al lado de un árbol vacío da un elemento que **se ve perfecto en el front, reporta éxito, y se abre vacío en el editor** — por eso podía arrasar un sitio entero sin que nadie lo notara (era el upstream #121, arreglado aquí en la 1.34.0). Desde entonces las dos mitades salen de **un único parseo** en `KarMCP_Atomic_Props::parse_html_children()`, y el id que se inventa para un nodo se escribe también en el marcado antes de reserializarlo, así que el par se ata a sí mismo por construcción y no por que dos pasadas coincidan. **Hay tres puertas a ese valor** (`html()`, la rama de array en `candidates_for()`, y el default de `children` en `coerce_shape()`): si añades una cuarta, deriva el árbol ahí también. Los elementos ya dañados no se reparan solos — se vuelve a aplicar el texto una vez y se reconstruye.

- **`post_type` tiene un límite duro de 20 caracteres** (`wp_posts.post_type` es `varchar(20)`). El prefijo `karmcp_` es largo; un CPT que se pase **no se registra y falla en silencio**. Por eso el CPT del Themer es `karmcp_theme_tpl`, no `karmcp_theme_template`.
- **Nunca adivines nombres de campos o controles.** Elementor y Spectra aceptan cualquier clave que les mandes sin quejarse, así que un nombre erróneo parece funcionar y no hace nada. Léelo del plugin.
- **Nunca escribas firmas de malware íntegras en disco** (ni en tests). Los escáneres del hosting ponen el archivo en cuarentena, lo cual lo **deja a cero sin borrarlo**: el `require_once` tiene éxito, la clase nunca se declara, y el fatal aparece lejos del origen. Por eso `class-security-malware-audit.php` ensambla sus patrones desde fragmentos en tiempo de ejecución.
- **`render.php` de un bloque debe hacer `echo`, no `return`** — WordPress lo envuelve en su propio buffer e ignora el retorno. Hay un test que lo fija sobre el código que genera `KarMCP_Block_Generator`.
- **Las categorías marcadas `'pro' => true` en el catálogo de herramientas desaparecen de la pestaña Tools** (`get_all_tools()` las filtra). Eso es correcto para grupos cuyas abilities no existen, pero marcar así un grupo implementado lo vuelve inalcanzable: sus slugs se siembran deshabilitados y luego se ocultan de la única pantalla que podría activarlos. Pasó con el Widget y el Block Builder hasta 1.12.0.

## Deuda conocida

Auditado el 2026-08-14. **La 1.2.0 fue una release de auditoría y vació la mitad de esta lista**: capacidades reales por post type en `create-post`/`update-post`, `read_post` en `get-post`, filtrado por tipo y `perm: readable` en `list-posts`, revalidación de cada salto en `safe_download()`, rate-limit y normalización de `scope` en OAuth, desinstalador completo, `load_plugin_textdomain()`, el falso positivo de `REPLACE()` y el código muerto de Memory. Lo de abajo es lo que sigue pendiente. **`duplicate-post` salió de esta lista en 1.1.0**: vive en `includes/class-post-duplicator.php` (la primitiva) y `includes/abilities/class-duplicate-abilities.php` (la herramienta), y es también sobre lo que se construye `create-translation`.

Fuera de esta lista: **lo del CI se cerró el 2026-08-23.** Lo que aquí decía ("no hay CI", verificado el 2026-08-14) dejó de ser cierto al día siguiente con la Pieza 0, y nadie corrigió esta línea; qué corre ahora y la trampa del verde hueco están en la sección CI. Lo de `languages/` se resolvió en la 1.27.0 — POT, `.po` y `.mo` están, y la sección Traducciones cuenta cómo se regeneran.

- **`includes/admin/class-admin.php` son ~5.800 líneas** mezclando 6 responsabilidades. `get_tool_catalog()` es un único método de ~1.840 líneas que solo devuelve un array. La extracción natural es a archivos de datos, patrón que el repo ya usa en `includes/widgets/catalog-*.php`.
- **Duplicación en abilities:** 168 registros repiten el literal completo; solo `class-database-abilities.php` y `class-wpcli-abilities.php` lo factorizan en un helper `ability()`. Esa es la plantilla a seguir.
- **Los `scope` OAuth se normalizan al emitirlos pero nadie los lee.** `KarMCP_OAuth_Bearer::permission_callback()` autentica el token y no mira su columna `scopes`. Hoy da igual —solo existe el scope `mcp`—, pero el día que haya un segundo scope, ese callback es donde hay que aplicarlo.
- **El desinstalador conserva a propósito el contenido de los CPT** (brand kits, plantillas del Themer, Skills). Es una decisión, no un olvido: son cosas que escribió una persona. Está documentada en `class-uninstaller.php` para que no se re-litigue.

## Documentos

| Archivo | Qué es |
|---|---|
| [docs/ROADMAP-SEO-A11Y-THEMER.md](docs/ROADMAP-SEO-A11Y-THEMER.md) | Themer extendido: **hecho** (referencia en el apéndice). SEO: **`audit-page-seo` hecho**. Accesibilidad: pendiente, con los seams verificados y el motor de reglas ya construido. |
| [docs/ROADMAP-SECURITY.md](docs/ROADMAP-SECURITY.md) | El apartado de Seguridad, para sustituir a Wordfence: CI, pestaña Security, `harden-site`, drop-in de fatales, `update-core`, módulo de vulnerabilidades y parcheo. Escrito para implementarse desde cero. |
| [docs/ROADMAP-OPTIMIZE.md](docs/ROADMAP-OPTIMIZE.md) | Continuación de la pestaña Optimize (1.11.0): prevención, autocargadas, cron, índices, coste por plugin. Lo que ya está hecho y lo que no debe entrar. |
| [docs/AUDIT-RENDIMIENTO-PLUGIN.md](docs/AUDIT-RENDIMIENTO-PLUGIN.md) | Lo que cuesta **este** plugin por petición (2026-08-19, medido): 1,5 MB en cada visita, 2,7 MB en cada petición REST, 3-4 consultas de opción evitables. Siete hallazgos, y la lista de sospechosos que resultaron inocentes. |
| [docs/ROADMAP-ELEMENT-EXTENSIONS.md](docs/ROADMAP-ELEMENT-EXTENSIONS.md) | Extensiones de elemento. Parte atómica **hecha** (1.13.0/1.13.1); **parte clásica pendiente y es la que más se usa** — los elementos atómicos solo existen con el Editor V4 activado y solo en lo construido después. Las costuras de ambos mundos están verificadas contra 4.2.2/Pro 4.2.1. |
| [docs/MAINTENANCE-UPSTREAM.md](docs/MAINTENANCE-UPSTREAM.md) | Nota interna de mantenimiento: qué se ha revisado del árbol de origen y qué divergencias no deben reimportarse. |
| [docs/WIDGETS-UNLIMITED-ELEMENTS.md](docs/WIDGETS-UNLIMITED-ELEMENTS.md) | Los doce widgets de Unlimited Elements que usa content.karcos.com, con sus claves reales leídas de instancias vivas. **Ninguno está en el catálogo curado y la introspección no los resuelve**, así que esto es la única descripción que existe de seis de ellos. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Cómo añadir una herramienta o una integración. |
| `NOTICE` / `LICENSE` | Atribución y licencia. **No tocar.** |
