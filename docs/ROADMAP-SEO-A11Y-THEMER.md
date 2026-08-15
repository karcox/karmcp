# Roadmap: SEO & Accesibilidad · Themer extendido

Plan para construir internamente las dos capacidades que sí justificaban la licencia Pro del upstream. Todo lo demás de aquel Pro (AI Chat, Migrate, Memory, integraciones de formularios y SEO, packs de Elementor) queda **descartado**.

> El Widget/Block Builder salió de esa lista de descartes: se implementó en 1.12.0 (`includes/sandbox/`), con un diseño propio y sin nada que importar del upstream, que tampoco lo tenía en su árbol libre.

Este documento está escrito contra el código real de este árbol tras la limpieza. Cada costura citada existe y está verificada.

---

## Contexto: qué se borró y qué se conservó

La limpieza eliminó la maquinaria de licenciamiento (`KarMCP_License`, `karmcp_fs()`, `KarMCP_Pro_Loader`, `pro-manifest.txt`, vistas de upsell, guard free⇄premium). **No se tocó el motor**, y ese es el punto: las dos features que queremos construir no requieren reconstruir infraestructura, solo enchufarse a filtros que ya están ahí.

Un solo flag sobrevive como seam nombrado:

```php
// includes/themer/class-themer-cpt.php
apply_filters( 'karmcp_themer_extended_tier', false )
```

Solo controla el aviso de administración. **No lo uses como puerta de funcionalidad** — la funcionalidad se enchufa por los filtros de abajo.

---

## Parte 1 — Themer extendido ✅ IMPLEMENTADO

**Estado: hecho.** `includes/themer/class-themer-extended.php`, enganchado desde `KarMCP_Themer_Module::register()`. Cobertura en `tests/ThemerExtendedTest.php` (20 tests). Lo que sigue documenta el diseño y sirve de referencia para mantenerlo.

Un hallazgo durante la implementación que no estaba en el plan original: `KarMCP_Themer_Index` **ya persistía** una `priority` por plantilla, pero el ranker por defecto del render controller devuelve `0` para toda fila. La prioridad era inerte. Hizo falta un quinto enganche, `karmcp_themer_rank`, o el constructor habría dejado fijar una prioridad que no hacía nada.

Otro: el JS del constructor (`assets/js/themer-conditions.js`) **ya soportaba** Exclude, prioridad y el buscador de objetos — es data-driven desde el esquema. No hubo que tocar una línea de JS. Solo faltaba el endpoint AJAX `karmcp_themer_object_search`, que ahora existe (nonce + `edit_posts`).



### Lo que ya funciona (y sorprende)

El motor libre **ya implementa** casi todo lo que el upstream vendía como Pro. Verificado leyendo el código:

- `KarMCP_Themer_Conditions::evaluate()` ya soporta **include y exclude**: *"A template matches when at least one include rule matches AND no exclude rule matches"*.
- `KarMCP_Themer_Resolver::resolve()` ya ordena por **especificidad → prioridad → id más reciente**, y recibe un `callable $ranker` para la prioridad.
- `KarMCP_Themer_Matcher_Registry` ya resuelve reglas por clave con especificidad asociada.

Es decir: exclude, prioridad y ranking **no hay que construirlos**. Están escritos y son parte del árbol libre. Lo único que faltaba en el tier libre era (a) la cuota, (b) matchers granulares, y (c) exponerlo en la UI.

### Los cuatro puntos de enganche

| Filtro | Fichero | Qué controla |
|---|---|---|
| `karmcp_themer_quota` | `class-themer-cpt.php:369` | `apply_filters( 'karmcp_themer_quota', 1, $type )` — máximo de plantillas por tipo. Devuelve `PHP_INT_MAX` para ilimitado. |
| `karmcp_themer_matchers` | `class-themer-matcher-registry.php:40` | El mapa de matchers. Añade entradas nuevas al array. |
| `karmcp_themer_selectors` | `class-themer-metabox.php:49` | Qué selectores acepta el guardado. Un selector no listado se rechaza al validar. |
| `karmcp_themer_condition_schema` | `class-themer-condition-schema.php:89` | El árbol de opciones del constructor de condiciones en el metabox. |

### Contrato de un matcher

Cada entrada del registry es:

```php
'<clave>' => array(
    'specificity' => <int>,
    'callback'    => static function ( array $rule, array $ctx ): bool { ... },
),
```

Los matchers libres y su especificidad, como referencia de escala:

| Clave | Especificidad |
|---|---|
| `entire-site` | 0 |
| `all-singular`, `all-archives` | 10 |
| `front-page`, `post-type`, `post-type-archive`, `tax-archive` | 20 |

`$rule['object']` es una cadena `clave:parametro`; usa `KarMCP_Themer_Matcher_Registry::param()` y `::param2()` para extraerlos. `$ctx` es el snapshot de la petición que produce `KarMCP_Themer_Context::from_query()` (`is_singular`, `post_type`, `queried_taxonomy`, `is_front_page`, …).

### Plan de implementación

1. **Cuota.** Engancha `karmcp_themer_quota` y devuelve `PHP_INT_MAX`. Una línea. Con eso ya tienes plantillas ilimitadas por tipo.
2. **Matchers granulares.** Añade vía `karmcp_themer_matchers`, con especificidad **por encima de 20** para que ganen a los amplios:
   - `post:<id>` — una entrada concreta (specificity 40)
   - `term:<taxonomia>:<term_id>` — un término concreto (30)
   - `author:<id>` / `author-archive:<id>` (30)
   - `date` — archivos por fecha (20)
3. **Selectores.** Registra las mismas claves en `karmcp_themer_selectors` o el guardado las rechazará. **Este es el paso que más se olvida**: un matcher sin selector funciona en el resolver pero no se puede guardar desde el metabox.
4. **UI.** Extiende `karmcp_themer_condition_schema` para exponer Exclude, prioridad y el buscador de objetos. Ojo: el buscador AJAX del upstream (`wp_ajax_karmcp_themer_object_search`) vivía en el overlay Pro y **no está aquí**; habrá que escribirlo.

**Estimación realista:** los pasos 1–3 son media jornada. El paso 4 (UI del constructor) es el grueso.

### Dónde vive

Crea `includes/themer/class-themer-extended.php` y engánchalo desde `KarMCP_Themer_Module::register()`. No reintroduzcas un `pro/` overlay: hay un solo nivel.

---

## Parte 2 — SEO y Accesibilidad

### El punto de enganche

`KarMCP_Page_Snapshot` ya reserva dos secciones para esto:

```php
// includes/class-page-snapshot.php
$seam = apply_filters( 'karmcp_page_snapshot_sections', array(), $post_id, $include, $args );
foreach ( array( 'a11y', 'seo' ) as $key ) { ... }
```

Devuelve un array con claves `a11y` y/o `seo`. Lo que no se resuelva degrada a un stub de "no disponible". Las secciones pesadas se cachean en transient 15 min salvo `$args['fresh']`.

Hay un segundo seam más fino:

```php
apply_filters( 'karmcp_page_snapshot_seo_lite', $result, $post_id )
```

para que un plugin SEO que no guarda en postmeta (All in One SEO usa tabla propia) aporte `h1_count`, `meta_title`, `meta_description`, `canonical`, `og_image`.

### Lo que hay que escribir desde cero

Estas clases **no están** en el árbol (eran del overlay privado) y son la dependencia real:

- `KarMCP_Color_Contrast` — matemática WCAG de ratio de contraste
- `KarMCP_Content_Extractor` — vista normalizada del contenido de la página
- `KarMCP_Seo_Meta` — lectura/escritura de meta en Yoast / Rank Math / core

`KarMCP_Content_Extractor` es la pieza central: las auditorías de SEO y de a11y consumen la misma vista normalizada. Escríbela primero.

### Las herramientas MCP

Registra con `karmcp_register_ability( string $name, array $args )` (`includes/class-schema-compat.php:304`). El patrón real, copiado de `class-snapshot-abilities.php`:

```php
karmcp_register_ability(
    'karmcp/audit-page-a11y',
    array(
        'label'               => __( 'Audit Page Accessibility', 'karmcp' ),
        'description'         => __( '...', 'karmcp' ),
        'category'            => 'karmcp',            // OBLIGATORIO
        'execute_callback'    => array( $this, 'execute' ),
        'permission_callback' => array( $this, 'check_read_permission' ),
        'input_schema'        => array( 'type' => 'object', 'properties' => array( ... ) ),
    )
);
```

> **Trampa documentada por el upstream:** si omites `'category' => 'karmcp'`, `wp_register_ability()` descarta la ability **en silencio**. No hay error; la herramienta simplemente no aparece.

Instancia el grupo en `KarMCP_Ability_Registrar` (el patrón está en la línea 177 con `KarMCP_Snapshot_Abilities`) y carga el fichero desde `KarMCP_Bootstrap::load_classes()`.

### Orden sugerido

1. `KarMCP_Content_Extractor` — base compartida.
2. `audit-page-seo` — solo lectura, sin dependencias nuevas más allá del extractor. Es la que antes da valor.
3. `KarMCP_Color_Contrast` + `audit-page-a11y`.
4. `add-alt-text-from-context` y `fix-color-contrast` — los dos que escriben.

### Regla no negociable para las dos que escriben

`fix-color-contrast` y `add-alt-text-from-context` deben ser **dry-run por defecto** y mutar solo con `apply: true`. Es el patrón del upstream y es el correcto: un agente propone en lote, un humano revisa, y solo entonces se escribe. Las escrituras van por la capa de datos de Elementor (nunca `update_post_meta` en crudo) para que queden en revisiones y sean reversibles.

### Límite conocido, asúmelo desde el principio

El cálculo de contraste es **best-effort**. Cuando el color de fondo se hereda y no se puede resolver, el resultado correcto es `inconclusive`, no un aprobado. El upstream lo documentaba así y es honesto: no prometas cobertura total. Un `inconclusive` marcado es útil; un falso "pasa" es peor que no auditar.

---

## Orden entre las dos partes

Empieza por **Themer extendido**. Razón: el 80% ya está construido y probado en el motor libre, así que son días, no semanas, y levanta un tope duro que hoy limita el uso real. SEO/A11y requiere escribir tres clases desde cero y es un proyecto de verdad.

## Tests

`tests/` corre sin WordPress sobre stubs (`tests/bootstrap.php`). Los matchers, la especificidad, la resolución de ganador y la matemática de contraste son **funciones puras** — cúbrelas ahí. Ejecuta con:

```bash
vendor/bin/phpunit
```

Cualquier cosa que toque el render real del front-end necesita verificación manual en un WordPress local; los stubs no la cubren.
