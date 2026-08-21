# Auditoría: el coste del propio plugin

> Escrita el 2026-08-19 contra el árbol de la 1.25.3, con medidas reales. Si algo aquí no cuadra con
> el código, gana el código — y corrige este archivo.
>
> Esto **no** es la pestaña Optimize, que mira el sitio ajeno ([ROADMAP-OPTIMIZE.md](ROADMAP-OPTIMIZE.md)).
> Esto mira lo que cuesta KarMCP en cada petición del sitio donde está instalado. La regla de aquel
> documento —*no se cachea, se quita trabajo que WordPress hace para nada*— se aplica igual, y en
> algún punto el plugin no se la aplica a sí mismo.

## Cómo se midió

El coste dominante de un plugin de este tamaño es **cuánto PHP se compila antes de que empiece a
pasar algo**. Se midió con `opcache_compile_file()` sobre el conjunto exacto de archivos que carga
cada ruta (compila sin ejecutar, así que aísla el parseo del trabajo):

```bash
php -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=256 bench.php
```

Máquina: PHP 8.5.5 ZTS, Windows. **Los milisegundos absolutos no son los de producción** —un Linux
con opcache caliente parsea una vez y luego nada—, pero las proporciones y los megabytes sí lo son,
y los megabytes son los que se pagan en memoria compartida y en `include` en cada petición.

El conjunto de archivos por ruta se extrajo de las tres listas de `require_once` de
`includes/class-bootstrap.php` (`load_classes()`, `load_ability_classes()`, `load_admin()`).

## El coste por petición

> Las tres filas son de la 1.25.3, **antes** del autoloader. La primera ya no es cierta: desde
> la 1.30.0 una visita anónima carga 402 KB en 43 archivos. Las otras dos siguen vigentes,
> porque el Hallazgo 2 sigue abierto.

| Ruta | Archivos | PHP compilado | Compilación en frío | opcache |
|---|---:|---:|---:|---:|
| Página pública anónima | 155 | **1.500 KB** (45.439 líneas) | ~450–590 ms | 12,9 MB |
| Cualquier petición REST | 231 | **2.698 KB** | ~1.042 ms | 16,1 MB |
| Cualquier petición wp-admin (incluye `admin-ajax.php`) | 234 | **2.974 KB** | ~1.118 ms | 16,7 MB |

La segunda fila es la que sorprende y se explica en el Hallazgo 2: **no es "cada llamada MCP", es
cada petición REST del sitio**, la sesión del editor de bloques incluida.

---

## Hallazgo 1 — 1,5 MB de PHP en cada visita anónima  ✅ RESUELTO EN 1.30.0

> **Cerrado.** El classmap y `KarMCP_Autoloader` sustituyeron la lista. Medido con el mismo
> método sobre la misma ruta: **1.548 KB en 157 archivos → 402 KB en 43**, un 74% menos. Lo que
> queda es lo que `wire_hooks()` toca de verdad, más los dos archivos que declaran una función
> global y no pueden autocargarse. `ClassmapTest` fija las propiedades que lo sostienen y
> `bin/check.ps1` falla si el mapa se desfasa. Lo de abajo se conserva como el análisis que
> justificó el cambio.

`KarMCP_Bootstrap::load_classes()` hace 155 `require_once` en `plugins_loaded:20`, incondicionalmente.
Ahí dentro va, entre otras cosas, el escáner de malware, el auditor de rendimiento, las auditorías de
SEO y accesibilidad, el extractor de contenido (46 KB), los tres clientes de bancos de imágenes, los
catálogos de Spectra y Kadence, los ocho archivos de OAuth, los generadores del sandbox y las
herramientas de WP-CLI. Un visitante no alcanza ninguna de esas clases.

La 1.16.2 ya hizo bien la mitad del trabajo —sacó las 76 clases de `abilities/` a carga diferida— y
el comentario de `load_ability_classes()` explica exactamente por qué. **El mismo razonamiento se
aplica a la lista que quedó** y nadie lo ha vuelto a aplicar.

Cuantificación conservadora, por análisis de referencias: **23 de esas 155 clases (≈195 KB) no tienen
ni un solo consumidor fuera de `includes/abilities/` y `includes/admin/`.** Es decir: se compilan en
cada visita a una página y solo pueden usarse desde una herramienta MCP o desde wp-admin. Las peores:

```
18,8 KB  KarMCP_Kadence_Blocks_Catalog
16,7 KB  KarMCP_A11y_Audit
15,2 KB  KarMCP_Block_Tree
13,6 KB  KarMCP_Url_Guard
12,6 KB  KarMCP_Database_Guard
12,6 KB  KarMCP_DB_Cleaner
11,6 KB  KarMCP_Catalog_Audit
 9,8 KB  KarMCP_Kadence_Pattern_Library
 9,0 KB  KarMCP_Spectra_Catalog
 8,4 KB  KarMCP_Post_Duplicator
 8,1 KB  KarMCP_Skill_Outline
 7,9 KB  KarMCP_WPCLI_Jobs
 7,9 KB  KarMCP_Site_Plan
 …y 10 más
```

Los 195 KB son el suelo, no el techo: cuenta solo referencias directas por nombre de clase. Muchas
otras del bloque se alcanzan **únicamente como callable en cadena** (`array( 'KarMCP_Schema_Compat',
'normalize_result' )`, `array( 'KarMCP_Skill_Store', 'register_post_type' )`, …), y PHP resuelve un
callable en cadena **cuando el hook dispara**, no cuando se registra. Ninguna de esas clases necesita
estar cargada en `plugins_loaded`.

### El arreglo: un autoloader, no una lista mejor

Editar la lista a mano la deja desfasada en dos releases. La estructura del árbol permite algo
determinista: **239 de los 241 archivos de clase declaran exactamente una clase** (las dos excepciones
son `security/class-fatal-handler-template.php` y `themer/widgets/class-themer-widget-classes.php`).

Un mapa de clases generado —`clase => ruta`, escrito por un script de `bin/` y comitido, al estilo del
classmap de Composer— más un `spl_autoload_register` sustituye a las **dos** listas de `require_once`
y hace que cada petición cargue solo lo que toca. Ventajas sobre lo que hay:

- No hay que decidir qué es "de runtime" y qué no: lo decide el uso real, petición a petición.
- Las trampas de orden que hoy documenta el bootstrap (el trait antes de las integraciones, cada base
  abstracta antes de sus subclases, store y loader juntos) las resuelve el autoloader solo.
- Es verificable: un test que compare el mapa contra el árbol falla en cuanto alguien añade un archivo
  y no regenera. Hoy la lista se desincroniza en silencio.

Los cuatro emparejamientos que el bootstrap declara obligatorios (`Block_Store`/`Block_Loader`,
`Extension_Store`/`Extension_Loader`) siguen siendo requisitos de **instanciación**, no de carga, y
esos `class_exists()` seguirían funcionando igual — solo que ahora dispararían el autoloader.

**Esfuerzo:** un día. **Riesgo:** bajo, y toda la suite lo cubre.

---

## Hallazgo 2 — Cada petición REST paga el registro MCP completo  ✅ RESUELTO EN 1.30.0

> **Cerrado, y con una corrección al diagnóstico.** Medido con el mismo método sobre
> `/wp-json/wp/v2/posts`: **3.020 KB en 238 archivos → 402 KB en 43**, un 87% menos, más los ~208
> registros de ability que ya no se construyen.
>
> **La cadena que se describe abajo no era la que disparaba el gasto.** El culpable es anterior:
> el adapter monta **su** servidor por defecto en `mcp_adapter_init` con prioridad **10**, antes
> que `register_mcp_server()` (que va en la 20), y `DefaultServerFactory::create()` llama dos veces
> a `wp_get_abilities()` para descubrir recursos y prompts *antes* incluso de llegar a
> `create_server()`. Eso ya fuerza la API perezosa y con ella toda nuestra registración. Declinar
> solo nuestro servidor no habría ahorrado nada: hace falta el filtro sobre
> `mcp_adapter_create_default_server`, y es lo que se implementó.
>
> **Y la salida 2 que se recomienda abajo no existe.** `McpComponentRegistry::register_ability_tool()`
> resuelve cada ability con `wp_get_ability()` al construir el servidor, así que "cachear la lista de
> nombres" produce un servidor con cero herramientas. Se implementó la salida 1, la del filtro por
> ruta — pero sin su pega: la puerta deja pasar el índice `/wp-json/`, así que la descubrición
> sigue funcionando, y prueba el **namespace** `mcp/` en vez de nuestra ruta, para no dejar en 404
> el servidor por defecto del adapter.
>
> Lo de abajo se conserva como el análisis que motivó el cambio.

Este es el mayor, y es el que no se ve leyendo `class-bootstrap.php`.

`vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:54-64` engancha su `init()` así:

```php
if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
    add_action( 'init', array( self::$instance, 'init' ), 20 );
} else {
    add_action( 'rest_api_init', array( self::$instance, 'init' ), 15 );
}
```

La buena noticia primero, porque conviene tenerla escrita: **en una página pública el adapter no
arranca**. No hay `rest_api_init`, no se dispara `mcp_adapter_init`, y el diferido de la 1.16.2
aguanta. Eso está bien diseñado y no hay que tocarlo.

La mala: `rest_api_init` dispara en **toda** petición REST, no solo en las de `/wp-json/mcp/…`. Y en
cuanto dispara, la cadena entera corre:

```
rest_api_init:15  → McpAdapter::init()
                  → do_action( 'mcp_adapter_init' )
                  → KarMCP_Plugin::register_mcp_server()  (prioridad 20)
                  → wp_abilities_api_init
                  → KarMCP_Plugin::register_abilities()
                  → KarMCP_Bootstrap::load_ability_classes()   ← 1.198 KB, 76 archivos
                  → KarMCP_Ability_Registrar::register_all()   ← 208 registros de ability
                  → $mcp_adapter->create_server( …, 130+ tools )
```

`register_mcp_server()` solo se protege con `is_server_enabled()` (encendido por defecto) y con
`empty( $this->ability_names )`. No hay ninguna comprobación de ruta.

Lo que eso significa en la práctica: **abrir el editor de bloques, guardar un borrador, cargar la
pantalla de un plugin que use REST, o cualquier petición a `/wp-json/wp/v2/…`** compila 1,2 MB de
clases de herramientas y registra 208 abilities con sus esquemas JSON. En una sesión de edición son
decenas de peticiones.

### Verificarlo antes de arreglarlo

El gancho ya existe. En `wp-config.php`:

```php
define( 'KARMCP_PROFILE_REGISTRATION', true );
```

y luego una petición a `/wp-json/` a pelo, sin tocar MCP. Si en el log aparece
`KarMCP: ability registration took … ms (N tools).`, el hallazgo está confirmado y además queda
medido en producción, que es donde importa.

### Las dos salidas, con su pega

1. **Filtrar por ruta.** En `register_mcp_server()`, salir temprano si la `REQUEST_URI` no cae bajo la
   ruta del servidor MCP. Barato y directo. **Pega:** el índice de descubrimiento de `/wp-json/` deja
   de listar la ruta MCP, y algún cliente la descubre por ahí. Hay que comprobarlo contra un conector
   real antes de darlo por bueno.
2. **Cachear la lista de nombres** (`ability_names`) en una opción autocargada, invalidada por
   `KARMCP_VERSION` y por el toggle de herramientas, y cargar las clases de `abilities/` solo cuando
   una llamada a herramienta llega de verdad. `create_server()` únicamente necesita **nombres** para
   construirse; los callbacks se resuelven al invocarse. Más trabajo, sin la pega de la descubrición.

La 2 es la correcta; la 1 sirve para medir la ganancia en una tarde antes de comprometerse.

---

## Hallazgo 3 — Tres consultas de opción no autocargada en cada petición

Tres `maybe_install()` se enganchan en `init:20` y arrancan siempre leyendo su marca de versión:

| Clase | Opción |
|---|---|
| `KarMCP_Search_Index` | `class-search-index.php:71` |
| `KarMCP_Change_Blobs` | `class-change-blobs.php:64` |
| `KarMCP_OAuth_Store` (vía `KarMCP_OAuth_Server::on_init`) | `class-oauth-store.php:59` |
| `KarMCP_Redirect_Store` (solo con el módulo activo) | `class-redirect-store.php:76` |

Las cuatro se escriben con `update_option( …, …, false )` — **autoload desactivado**. Sin caché de
objetos persistente eso es un `SELECT` por opción y por petición, solo para preguntar "¿ya está
creada la tabla?". Tres o cuatro consultas en cada visita, para una respuesta que solo cambia cuando
se actualiza el plugin.

Es exactamente el tema de la **Pieza C** del roadmap de Optimize, con el plugin en el lado equivocado
del mostrador.

**Arreglo**, por orden de preferencia:

1. Comparar contra `KARMCP_VERSION` en una única opción **autocargada** (`karmcp_db_version`), y
   ejecutar los cuatro `maybe_install()` solo cuando difiera. Una lectura de `alloptions`, cero
   consultas.
2. O mover la instalación al hook de activación más una comprobación de upgrade en `admin_init`, que
   es donde WordPress espera que ocurra un `dbDelta`.

Hoy, además, `dbDelta` puede dispararse desde una petición de un visitante anónimo, que no es el sitio
donde uno quiere que ocurra una migración de esquema.

**Esfuerzo:** una hora.

---

## Hallazgo 4 — El redirector consulta la base en cada página

Con el módulo Redirects activo, `KarMCP_Redirect_Handler::maybe_redirect()` corre en
`template_redirect:1` de toda petición pública y llama a
[`find_by_source()`](../includes/redirects/class-redirect-store.php):

```php
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE source_path = %s LIMIT 1', $path ), ARRAY_A );
```

Sin `wp_cache_*`, sin transient: **una consulta por visita**, y la abrumadora mayoría devuelve nada,
porque casi ninguna URL del sitio es una redirección. El índice la hace barata, pero barata por cero
visitas sigue siendo más que cero.

El patrón correcto ya está escrito en este mismo árbol y funciona:
[`KarMCP_Themer_Index`](../includes/themer/class-themer-index.php) mantiene **una opción autocargada**
con el índice completo, reconstruida en `save_post`/`delete`, y su docblock lo dice con todas las
letras — *"el resolver ejecuta cero consultas por petición"*. `KarMCP_Redirect_Store` debería hacer lo
mismo: un mapa autocargado `source_path => id` (o el conjunto de rutas, si el volumen aconseja no
meter las filas enteras), y bajar a la tabla solo cuando hay acierto.

Dos notas de paso sobre el mismo método:

- El nombre de tabla va **concatenado**, no con `%i`. CLAUDE.md lo prohíbe explícitamente ("no vuelvas
  a concatenar"), y `class-redirect-store.php` lo hace en varios sitios. No es explotable —`table()`
  se construye desde `$wpdb->prefix`— pero es la regla de la casa y aquí no se cumple.
- `record_hit()` hace un `UPDATE` por redirección servida. Correcto de intención, pero en una URL
  redirigida que reciba tráfico real es una escritura por visita sobre la misma fila. Si alguna vez
  molesta, el contador agrupado por lotes es la salida.

**Esfuerzo:** medio día.

---

## Hallazgo 5 — WebP: un `file_exists()` por URL y por candidato de srcset

`KarMCP_Webp_Rewriter::maybe_rewrite()` (módulo Image Optimization) termina en:

```php
if ( '' === $path || ! file_exists( $path . '.webp' ) ) {
```

y se engancha a `wp_get_attachment_url`, `wp_get_attachment_image_src` y **`wp_calculate_image_srcset`**.
Ese último recibe el srcset entero y el filtro recorre **cada candidato**. Una página con 20 imágenes
y 5 tamaños por srcset son ~100 `stat()` al sistema de archivos por petición, sin memoización — y en
almacenamiento de red (NFS, algunos discos de contenedor) un `stat()` no es gratis.

Encima, `allowed()` vuelve a llamar a `sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) )`
en cada una de esas llamadas, cuando la respuesta es constante durante toda la petición.

**Arreglo**, quince minutos y sin cambiar comportamiento:

- Memoizar `allowed()` en una propiedad estática por petición.
- Memoizar la existencia del hermano `.webp` en un `array $seen[$path]` de instancia. Un mismo
  adjunto aparece varias veces en la misma página (`src` + srcset + lazy), así que el acierto de caché
  es alto por construcción.

---

## Hallazgo 6 — wp-admin carga las 234 clases en cada `admin-ajax.php`

`KarMCP_Bootstrap::boot()` hace `if ( is_admin() ) { self::load_admin(); }`, y `load_admin()`
llama a `load_ability_classes()` sin condición ("wp-admin es un consumidor de herramientas", dice el
comentario — y para las pantallas de KarMCP es cierto).

Pero `is_admin()` también es cierto en **`admin-ajax.php`**. O sea que el latido del corazón del
editor, cada autoguardado, y cada llamada AJAX de cualquier otro plugin del sitio compilan los
**2.974 KB** completos de KarMCP. Con un editor abierto eso es un tic cada 15–60 s indefinidamente.

**Arreglo:** condicionar `load_admin()` a que la petición sea nuestra:

- `admin-ajax.php` → cargar solo si `$_REQUEST['action']` empieza por `karmcp_` (todos los handlers
  llevan el prefijo, están en `class-admin.php:240-259`).
- Resto de wp-admin → `load_ability_classes()` puede esperar a `current_screen` o al primer uso; las
  pantallas que lo necesitan son las de KarMCP, no la lista de entradas.

El registro de los `wp_ajax_karmcp_*` sí tiene que ocurrir siempre, pero eso son `add_action()` con
callable en array, que no requieren la clase cargada hasta que el hook dispara.

**Esfuerzo:** media jornada, con cuidado en el reparto.

---

## Hallazgo 7 (menor) — El log MCP es una opción leída y reescrita por llamada

`KarMCP_MCP_Request_Log::record()` lee la opción, añade una fila, recorta a 100 y hace `update_option`.
Por llamada MCP: una lectura y una escritura de una opción que puede llevar 100 registros serializados,
más la invalidación de la caché de opciones. Con llamadas MCP concurrentes, además, se pelean por la
misma fila.

Aceptable como está; si alguna vez el rendimiento MCP importa, una tabla propia o un fichero rotado
quitan la contención. Se anota, no se prioriza.

---

## Lo que se miró y está bien

Vale la pena dejarlo escrito, porque son los sospechosos obvios y no lo son:

- **`get_tool_catalog()` no es un problema de rendimiento.** Los ~1.880 líneas y 251 entradas asustan,
  y CLAUDE.md ya las señala como deuda de mantenimiento. Medido en aislamiento: **0,21 ms por
  llamada**. Se reconstruye 3–4 veces en la pantalla Tools y sigue sin importar. Es deuda de
  *estructura*, no de velocidad — no lo refactorices por rendimiento.
- **`KarMCP_Themer_Index` es el patrón correcto** y ya está implementado: opción autocargada, cero
  consultas por petición, reconstrucción en `save_post`. Es el modelo para el Hallazgo 4.
- **El arranque del adapter es genuinamente perezoso en el frontend** (`rest_api_init`, no `init`).
  El diferido de la 1.16.2 aguanta en las páginas públicas. El problema del Hallazgo 2 es el alcance
  de `rest_api_init`, no el diseño de KarMCP.
- **Los seis CPT de runtime** se registran con `'public' => false` y `'rewrite' => false`. Cero reglas
  de reescritura añadidas, cero coste de `flush_rewrite_rules`.
- **Ningún `ob_start()` global en el frontend.** Los seis que hay están dentro de renderizadores
  concretos con su `ob_get_clean()` al lado.
- **`KarMCP_Data::flush_element_cache_on_data_write()`** se engancha a `added_post_meta`/`updated_post_meta`,
  que disparan en toda escritura de meta del sitio — pero el cuerpo es una comparación de cadena y
  vuelve. Correcto.
- **`KarMCP_Search_Index::on_save_post()`** está bien vallado (autosave, revisión, versión de tabla,
  Elementor listo, tipo de post) antes de indexar nada.
- **Ningún `posts_per_page => -1` en camino de petición.** Los diez que hay viven en stores,
  reindexados y optimizadores por lotes, que es donde deben estar.
- **`KarMCP_PHP_Snippet_Store`, `Block_Store` y `Extension_Store` sirven desde manifest en fichero**,
  no desde la base de datos, tal como promete CLAUDE.md. El coste por petición es leer un JSON pequeño
  y verificar el sha256 de los snippets activos — proporcional a lo que hay activo, no al catálogo.

---

## Plan, por retorno sobre esfuerzo

| # | Qué | Gana | Esfuerzo |
|---|---|---|---|
| 1 | Confirmar el Hallazgo 2 con `KARMCP_PROFILE_REGISTRATION` en un sitio real | Ahora confirma que la puerta cierra: el log dice qué ruta se saltó | 30 min |
| 2 | ~~Marca de versión única y autocargada (H3)~~ **hecho en 1.26.0** (`KarMCP_Schema_State`) | 3–4 consultas por visita | 1 h |
| 3 | ~~Memoizar `allowed()` y el hermano `.webp` (H5)~~ **hecho** | Hasta ~100 `stat()` por página | 15 min |
| 4 | ~~Autoloader por classmap generado (H1)~~ **hecho en 1.30.0** | 1,5 MB → 402 KB medidos | 1 día |
| 5 | ~~Cortar la carga de herramientas en peticiones REST ajenas (H2)~~ **hecho en 1.30.0** | 2,6 MB medidos por petición REST | 1 día |
| 6 | Índice autocargado de redirecciones, al estilo Themer (H4) | 1 consulta por visita | ½ día |
| 7 | ~~Acotar `load_admin()` a peticiones nuestras (H6)~~ **hecho en 1.26.0** | 2,9 MB en cada tic de heartbeat | ½ día |

Los tres primeros caben en una mañana y no tocan arquitectura. El 4 es el que cambia la forma del
plugin, y a partir de ahí el 1 y el 6 son mucho menos urgentes porque ya no se carga lo que no se usa.

> **Al día de hoy solo queda abierto el 6** (Hallazgo 4, el índice de redirecciones), más el
> Hallazgo 7, que se anotó para no priorizarlo. El 1 pasó de "confirmar el hallazgo" a
> "verificar en producción que la puerta cierra", que es media hora bien empleada.
>
> Y una corrección al párrafo de arriba, que se escribió antes de implementar nada: **el autoloader
> no rebajó el Hallazgo 2**. `KarMCP_Ability_Registrar::register_all()` nombra las 64 clases de
> herramientas al registrarlas, así que en una petición REST el autoloader las habría cargado
> igual. Lo que sí hizo fue dejar el arreglo al alcance.

## Cómo reproducir las medidas

```bash
# 1. El conjunto de archivos de cada ruta, leído del bootstrap.
awk '/private static function load_classes/,/^\t}/' includes/class-bootstrap.php \
  | grep -o "includes/[a-z0-9/_-]*\.php" | sort -u > runtime.txt

# 2. Compilar sin ejecutar y cronometrar.
php -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=256 bench.php runtime.txt .
```

`bench.php` recorre la lista con `opcache_compile_file()`, suma `filesize()` y lee
`opcache_get_status()` para la memoria compartida. El análisis de referencias del Hallazgo 1 extrae
el nombre de clase de cada archivo y busca menciones en todo `includes/` excluyendo `abilities/` y
`admin/`.
