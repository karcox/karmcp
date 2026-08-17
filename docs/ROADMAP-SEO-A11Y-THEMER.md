# Roadmap: SEO y Accesibilidad

Plan para darle al plugin lo único que hoy le falta: **juzgar su propio resultado**.

KarMCP ya sabe construir —son la mayoría de sus herramientas— y desde `render-page` ya sabe mirar. Lo que no sabe es decir si lo que acaba de construir está bien. Este trabajo cierra ese bucle:

```
construir → renderizar → auditar → arreglar → volver a auditar
```

Ninguna de las dos familias vecinas puede cerrarlo. A un plugin de SEO o a un escáner de accesibilidad les faltan las manos: pintan el semáforo en rojo y ahí se acaban. A los demás servidores MCP les faltan los ojos: escriben lo que les pidas y no opinan del resultado. Aquí están las dos mitades en el mismo proceso.

Y las manos **ya están escritas**: "este H2 no dice nada" se arregla con `update-widget`, "falta la meta descripción" con `seo-write`, "esta imagen no tiene alt" con la capa de medios. Todas existen. La auditoría no añade capacidad de ejecución — **desbloquea la que ya hay**, porque hasta ahora el agente no sabía qué había que arreglar.

Este documento está escrito contra el código real. Cada costura citada existe y está verificada.

> El **Themer extendido**, que compartía documento con esto, está **implementado**: `includes/themer/class-themer-extended.php`, cubierto por `tests/ThemerExtendedTest.php` (20 tests). Su referencia de mantenimiento está en el [apéndice](#apéndice--referencia-del-themer-extendido), al final.

---

## El punto de enganche

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

> **Actualizado 2026-08-15: la dependencia cara ya no lo es.** Este apartado listaba tres clases; `KarMCP_Content_Extractor` se escribió para `render-page` (1.1.0) y vive en `includes/class-content-extractor.php`. Era la pieza central y el motivo por el que este trabajo se aplazaba una y otra vez. **Ya no bloquea nada.**

- ~~`KarMCP_Seo_Meta`~~ — **hecho**, `includes/class-seo-meta.php`. Una sola línea de campos sobre Yoast, Rank Math y Slim SEO. AIOSEO y SEOPress se detectan pero **no se leen**, a propósito: guardan en tablas propias y adivinar un esquema ajeno produce un lector que devuelve cadenas vacías para siempre. El seam para ellos es el filtro `karmcp_seo_meta`.
- ~~`KarMCP_Color_Contrast`~~ — **hecho**, `includes/audits/class-color-contrast.php`. La matemática es exacta; lo que no lo es sigue siendo saber **qué dos colores comparar**, y ahí manda la regla de abajo. Añadido sobre el plan original: cuando no se conoce el tamaño de letra —lo normal, porque vive en la hoja de estilos— dos tercios del rango siguen teniendo respuesta rigurosa, y solo la banda entre 3:1 y 4.5:1 depende de él.

Y una que no estaba en la lista original porque no se había pensado el diseño:

- ~~`KarMCP_Audit_Rules`~~ — **hecho**, aunque con otra forma: `KarMCP_Audit_Score` (`includes/audits/class-audit-score.php`) es la parte compartida —pesos, nota, recuento— y las reglas viven con su auditoría, en `KarMCP_Seo_Audit`. Un registro de reglas con callbacks habría sido maquinaria para un solo consumidor; cuando llegue a11y, lo que tiene que compartir ya está separado.

### El motor de reglas

`KarMCP_Content_Extractor::analyze()` ya devuelve la vista que las dos auditorías necesitan: outline de encabezados, enlaces, imágenes, formularios, texto visible y las advertencias del render. **Una auditoría es una lista de reglas sobre ese digest**, no un analizador nuevo.

Contrato de una regla, calcado del que ya funciona en `includes/performance/class-performance-finding.php`:

```php
array(
    'id'          => 'img-missing-alt',
    'standard'    => 'wcag-1.1.1',   // o 'seo' / 'aeo'
    'severity'    => 'error',        // error | warning | notice
    'callback'    => static function ( array $digest, array $ctx ): array { ... },
)
```

Cada regla es **pura**: digest entra, hallazgos salen. Esa es la propiedad que hace que la suite las cubra entera sin WordPress, y es la misma apuesta que ya se hizo con los generadores del sandbox. Una regla que necesite consultar la base de datos está mal planteada: el dato que le falta debe entrar por `$ctx`, que lo reúne el llamante.

> **No reinventes el reporte.** `KarMCP_Performance_Finding` ya define la forma de un hallazgo y la pestaña Optimize ya sabe pintarla. Reutiliza esa estructura o acabarás con dos vocabularios de severidad que no casan.

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

> **Trampa:** si omites `'category' => 'karmcp'`, `wp_register_ability()` descarta la ability **en silencio**. No hay error; la herramienta simplemente no aparece.

Instancia el grupo en `KarMCP_Ability_Registrar` (el patrón está en la línea 177 con `KarMCP_Snapshot_Abilities`) y carga el fichero desde `KarMCP_Bootstrap::load_classes()`.

### Orden sugerido

~~1. `KarMCP_Content_Extractor` — base compartida.~~ **Hecho** (`includes/class-content-extractor.php`).

~~1. **`KarMCP_Seo_Meta` + `audit-page-seo`**~~ — **hecho.** `includes/audits/class-seo-audit.php` y `includes/abilities/class-seo-audit-abilities.php`, cubiertos por `tests/SeoAuditTest.php` y `tests/SeoMetaTest.php` (56 tests). Rellena además la sección `seo` de `get-page-snapshot`, que llevaba desde el principio reservando el seam y devolviendo un stub vacío.

~~**Legibilidad**~~ — **hecha** en 1.15.0. `includes/audits/class-readability.php`, dentro del informe de SEO como se planteó. Szigriszt-Pazos + INFLESZ para español, Flesch para inglés, idioma resuelto por post vía Polylang/WPML. 46 tests, con 28 palabras fijadas una a una.

~~**`KarMCP_Color_Contrast` + `audit-page-a11y`**~~ — **hechos** en 1.16.0. `includes/audits/class-color-contrast.php` y `class-a11y-audit.php`, 47 tests entre los dos. Rellenan también la sección `a11y` de `get-page-snapshot`, con lo que **las dos mitades del seam quedan contestadas**.

Lo que queda:

1. **Persistencia de escaneos** — listar, abrir uno viejo, comparar. Ver abajo; ahora ya sirve para las dos auditorías a la vez, que era la razón de ponerla después.
2. **`add-alt-text-from-context` y `fix-color-contrast`** — los dos que escriben.
3. **AEO** — opcional. Ya no hay que montar nada para ello: las reglas se añaden al mismo sitio que las de SEO. Ver abajo.
4. **Core Web Vitals** — fuera de este plan. Ver abajo.

### Cuánto es esto, honestamente

Estimación en sesiones de trabajo enfocado, no en días de calendario:

| Pieza | Coste | Riesgo |
|---|---|---|
| ~~`KarMCP_Seo_Meta`~~ | ~~1~~ | **Hecho** |
| ~~Motor de reglas + `audit-page-seo`~~ | ~~2–3~~ | **Hecho** |
| ~~Legibilidad~~ | ~~½~~ | **Hecha** |
| ~~`KarMCP_Color_Contrast`~~ | ~~½~~ | **Hecho** |
| ~~Reglas a11y + `audit-page-a11y`~~ | ~~2–3~~ | **Hechas** |
| Persistencia + pestaña | 1–2 | Bajo — se copia el patrón de Security |
| Las dos que escriben | 2 | **Medio-alto** — tocan render real, los stubs no lo cubren |
| **Total pendiente** | **3–4** | |

Lo importante no es el total sino que **es incremental de verdad**, y el primer paso ya lo demostró: `audit-page-seo` sola es una herramienta completa y útil, y nada de lo que queda es requisito suyo. No hay un punto en el que haya que tenerlo todo para que algo funcione. Se puede parar después de cualquier paso.

Lo que no está en la tabla porque no se ha diseñado: la pestaña de administración si se quiere que esto se vea sin un agente delante. Súmale 1–2 sesiones si se decide hacerla.

> **La incertidumbre real está en el paso 2**, y conviene decirla antes de empezar: todo lo demás es lógica pura que la suite cubre. Las dos herramientas que escriben tocan el render del front-end, donde `tests/` no llega, y necesitan verificación manual en un WordPress local.

### Regla no negociable para las dos que escriben

`fix-color-contrast` y `add-alt-text-from-context` deben ser **dry-run por defecto** y mutar solo con `apply: true`: un agente propone en lote, un humano revisa, y solo entonces se escribe. Las escrituras van por la capa de datos de Elementor (nunca `update_post_meta` en crudo) para que queden en revisiones y sean reversibles.

### Límite conocido, asúmelo desde el principio

El cálculo de contraste es **best-effort**. Cuando el color de fondo se hereda y no se puede resolver, el resultado correcto es `inconclusive`, no un aprobado. No prometas cobertura total: un `inconclusive` marcado es útil, y un falso "pasa" es peor que no auditar, porque cierra el tema.

En un sitio de Elementor esto va a pasar **mucho**: colores globales, fondos heredados de un contenedor padre, degradados, imágenes de fondo. Cuenta con que una parte grande de los textos salga `inconclusive` y diséñalo para que eso no parezca un fallo de la herramienta, porque no lo es.

### Quién escribe el texto alternativo

`add-alt-text-from-context` **no genera el texto**. La tentación es que PHP invente un alt a partir del nombre del fichero, y eso produce basura plausible, que es peor que un hueco visible.

El reparto correcto, que además es el que ya usa el resto del plugin:

1. La herramienta **encuentra los huecos** y devuelve el contexto de cada imagen: dónde está, qué encabezado la precede, qué texto la rodea, el nombre del fichero, si es decorativa por su rol.
2. El **agente propone** los textos, que es exactamente para lo que sirve.
3. Un **humano aprueba**.
4. La herramienta **escribe** los valores aprobados, por la capa de datos de Elementor.

Es el mismo reparto que en el sandbox: el agente declara, el plugin compila. Aquí el agente redacta y el plugin persiste.

### Deuda conocida del extractor: HTML minificado

`visible_text()` extrae con `textContent`, que **concatena sin separador**. En HTML normal hay saltos de línea e indentación entre etiquetas y las palabras quedan sueltas, pero un plugin de caché que minifique la salida los elimina — y entonces la última palabra de un bloque y la primera del siguiente se funden en una.

Efecto real: `text.words` **subcuenta** en un sitio con HTML minificado, y de ese contador cuelgan los umbrales de contenido escaso (100 y 300 palabras). No afecta a `text.prose`, porque los párrafos se recogen uno a uno y se unen explícitamente.

Salió al escribir los tests de la prosa. No se arregló en 1.15.1 por no tocar la salida de `render-page` en la misma release que arreglaba otra cosa; el arreglo es insertar un espacio al extraer texto de elementos de bloque.

### Legibilidad: decide la fórmula antes de escribir la función

El **Flesch Reading Ease está calibrado para el inglés**. Aplicado a un texto en español devuelve un número que parece válido y no lo es: el español tiene más sílabas por palabra de media, así que todo sale artificialmente "difícil".

Para español la fórmula correcta es **Fernández Huerta** o **Szigriszt-Pazos** (perspicuidad), interpretada con la escala INFLESZ. Es la misma forma con otras constantes, así que el coste extra de hacerlo bien es cero — pero hay que decidirlo antes, no después.

Ventaja inesperada: **contar sílabas en español es más fiable que en inglés**. Las reglas de grupo vocálico, diptongo e hiato son regulares y se implementan exactas; en inglés hace falta un diccionario o una heurística que falla. Sale un contador correcto donde el resto del mercado tiene una aproximación.

El idioma se resuelve por post: `get_locale()`, o Polylang / WPML si están activos — las dos integraciones ya existen. Si el idioma no tiene fórmula conocida, el resultado es `unsupported`, no un número inventado. Misma regla que el `inconclusive` del contraste.

### Persistencia: una auditoría es un registro, no un mensaje

Un escaneo que solo existe dentro de la conversación se pierde. Hay que poder listarlos, abrir uno viejo y ver si el sitio mejoró.

**El patrón ya está montado en la pestaña Security**: guarda score, grado y hallazgos, se refresca a diario en segundo plano, y muestra un escaneo viejo o fallido **como exactamente eso**, nunca como un aprobado. Esa última propiedad es la que hay que conservar al copiarlo.

Herramientas: `list-audits` y `get-audit`, con filtro por tipo (`seo` / `a11y`) y por post. Se implementa una vez y sirve para las dos auditorías; por eso va después de la de accesibilidad y no antes.

### AEO — opcional, barato si va pegado al SEO

Comprobaciones de si la página es legible por un motor de búsqueda con modelo detrás. Casi todo sale del mismo digest, más dos lecturas de fichero:

- Existencia y forma de `llms.txt`
- Si `robots.txt` deja pasar a GPTBot / ClaudeBot / PerplexityBot — **informar, no decidir**: bloquearlos es una postura legítima del dueño del sitio, y la herramienta no debe tener opinión
- Encabezados con forma de pregunta
- Secciones que se sostienen solas al arrancarlas del documento, que es como las lee un modelo
- Fechas, autoría y entidades declaradas en el schema

Está aquí porque es la única parte de esta familia que no está ya inventada en otros diez plugins, y porque su coste marginal es pequeño: las reglas se añaden a `KarMCP_Seo_Audit`, que ya existe, y salen en el mismo informe. **No es un requisito**: si hay que recortar, esto es lo primero que sale.

### Core Web Vitals — fuera de aquí, y por qué

No entra en este plan: no es una auditoría, es infraestructura. Queda anotado lo que no hay que redescubrir:

- Es **INP**, no FID — Google lo sustituyó en marzo de 2024. Una implementación que hable de FID nace caducada.
- LCP, INP y CLS son métricas **de campo**: salen de visitas reales y **no se calculan desde PHP**.
- La vía fácil (API de PageSpeed / CrUX) tiene dos taras: **le dice a un tercero qué URL auditas en cada auditoría** —incoherente con el módulo de vulnerabilidades, que se baja el feed entero de Wordfence justo para no contar nada— y **CrUX no tiene datos** para sitios con poco tráfico, que son la mayoría de los de cliente.
- La vía coherente es RUM propio, y cuesta tabla, endpoint, JS en el front, cron y retención. El día 1 no hay datos.

Si alguna vez se hace, se decide en [ROADMAP-OPTIMIZE.md](ROADMAP-OPTIMIZE.md). `analyze-performance` ya existe y contesta ahora.

---

## Tests

`tests/` corre sin WordPress sobre stubs (`tests/bootstrap.php`). **Cada regla del motor de auditoría**, la matemática de contraste y el contador de sílabas son funciones puras — cúbrelas ahí. Es la mayor parte de este trabajo. Ejecuta con:

```bash
vendor/bin/phpunit
```

Cualquier cosa que toque el render real del front-end necesita verificación manual en un WordPress local; los stubs no la cubren. Es el riesgo de las dos herramientas que escriben.

Estado tras `audit-page-seo`: **838 tests, 2.095 aserciones, todo en verde** (2026-08-16), de los cuales 56 son de esta parte (`tests/SeoAuditTest.php`, `tests/SeoMetaTest.php`).

---

## Apéndice — referencia del Themer extendido

Implementado; esto no es plan, es mantenimiento. Vive en `includes/themer/class-themer-extended.php`, enganchado desde `KarMCP_Themer_Module::register()`.

> `apply_filters( 'karmcp_themer_extended_tier', false )` (`class-themer-cpt.php`) **solo controla el aviso de administración**. No lo uses como puerta de funcionalidad: la funcionalidad se enchufa por los filtros de abajo.

| Filtro | Fichero | Qué controla |
|---|---|---|
| `karmcp_themer_quota` | `class-themer-cpt.php:369` | Máximo de plantillas por tipo. `PHP_INT_MAX` para ilimitado. |
| `karmcp_themer_matchers` | `class-themer-matcher-registry.php:40` | El mapa de matchers. |
| `karmcp_themer_selectors` | `class-themer-metabox.php:49` | Qué selectores acepta el guardado. **Un matcher sin selector funciona en el resolver pero no se puede guardar desde el metabox** — es lo que más se olvida. |
| `karmcp_themer_condition_schema` | `class-themer-condition-schema.php:89` | El árbol de opciones del constructor en el metabox. |
| `karmcp_themer_rank` | — | Quinto enganche, añadido durante la implementación: `KarMCP_Themer_Index` ya persistía una `priority`, pero el ranker por defecto devuelve `0` para toda fila. Sin esto, la prioridad es inerte. |

Contrato de un matcher:

```php
'<clave>' => array(
    'specificity' => <int>,
    'callback'    => static function ( array $rule, array $ctx ): bool { ... },
),
```

Escala de especificidad de los matchers base: `entire-site` 0; `all-singular` y `all-archives` 10; `front-page`, `post-type`, `post-type-archive` y `tax-archive` 20. Los granulares van por encima de 20 para ganarles.

`$rule['object']` es una cadena `clave:parametro`; extráela con `KarMCP_Themer_Matcher_Registry::param()` y `::param2()`. `$ctx` es el snapshot que produce `KarMCP_Themer_Context::from_query()`.
