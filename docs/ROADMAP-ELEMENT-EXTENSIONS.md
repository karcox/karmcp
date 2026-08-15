# Extensiones de elemento (Elementor 4.2+)

> **Estado: implementado en 1.13.0.** Este documento se escribió como plan y se
> conserva como referencia de diseño: las costuras verificadas de §1, los
> callejones sin salida ya explorados y el porqué de cada regla del spec. Lo que
> quedaba abierto —si el panel pinta una sección añadida desde PHP sin paquete
> JS— se resuelve con la primera extensión real, no con una prueba aparte.


Tercera clase de artefacto del sandbox, junto a los widgets y los bloques. Un
**widget** es algo que insertas; una **extensión de elemento** es una opción que
aparece en elementos que ya existen — un control nuevo en el panel de cualquier
contenedor, que hace algo cuando lo enciendes.

El caso que lo motiva: "quiero poder activar partículas en cualquier contenedor",
igual que hacía Elementor Pro en la 3.x.

Escrito contra Elementor **4.2.2** y Elementor Pro **4.2.1**, leídos archivo a
archivo el 2026-08-15. Cada costura citada existe y está verificada. **Solo
apunta a la 4.2+**: los elementos clásicos y sus hooks quedan fuera por decisión
explícita del proyecto.

---

## 1. Qué se puede y qué no en la 4.2

Esta sección es el resultado de la investigación y es lo que fija el diseño. Si
algo aquí deja de ser cierto en una versión futura de Elementor, el diseño hay
que revisarlo, no parchearlo.

### Lo que sí se puede, solo con PHP

**Declarar props nuevas en cualquier elemento atómico.**

```php
// El filtro recibe el schema de props de cada elemento por separado.
add_filter( 'elementor/atomic-widgets/props-schema', function ( array $schema ) {
    $schema['karmcp_particles'] = String_Prop_Type::make()->default( 'none' );
    return $schema;
} );
```

Definido en `modules/atomic-widgets/elements/base/has-atomic-base.php:367`.
Ejemplo real en Pro: `modules/atomic-form/classes/akismet.php:16`.

> **Sin este paso la prop no se guarda.** `parse_atomic_settings()` valida lo
> guardado contra el schema, así que una prop que no esté declarada se descarta
> en silencio al guardar la página.

**Añadir controles y secciones enteras al panel.**

```php
add_filter( 'elementor/atomic-widgets/controls', function ( array $controls, $element ) {
    $controls[] = Section::make()
        ->set_label( 'Efectos' )
        ->set_items( [ Select_Control::bind_to( 'karmcp_particles' )->set_label( 'Partículas' ) ] );
    return $controls;
}, 10, 2 );
```

Definido en `has-atomic-base.php:200` (`get_atomic_controls()`). Ejemplo real en
Pro: `akismet.php:34` crea una sección propia; `modules/attributes/module.php:54`
inyecta un control dentro de la sección existente `settings`, localizándola con
`$item instanceof Section && $item->get_id() === 'settings'`.

El filtro recibe **la instancia del elemento** como segundo argumento, que es
como se decide a qué elementos aplica.

> `get_valid_controls()` descarta cualquier control cuya prop no esté en el
> schema. Los dos filtros son un par: uno sin el otro no hace nada.

**El schema no se cachea.** `get_props_schema()` (`has-atomic-base.php:359`)
llama a `define_props_schema()` y aplica el filtro **en cada invocación**. La
`Cache_Validity` del módulo atómico es solo para estilos
(`Styles\CacheValidity`). Activar o desactivar una extensión surte efecto en la
siguiente petición, sin invalidar nada.

**El editor renderiza en el servidor.** `render-element-action.php:56` llama a
`$element->print_element()`, el mismo método que dispara
`elementor/frontend/before_render`. Es decir: **lo que la extensión añada al
wrapper se ve también en el canvas del editor**, no solo en el front. Es lo que
hace usable un efecto: se activa y se ve.

**Catálogo de controles listos**, todos con su contraparte JavaScript ya
registrada en el editor (`modules/atomic-widgets/controls/types/`):

`text` · `textarea` · `number` · `select` · `switch` · `toggle` · `size` ·
`link` · `image` · `svg` · `video` · `chips` · `repeatable` · `html-tag` ·
`date-time` · `date-range` · `time-range` · `inline-editing` · `query` ·
`query-chips` · `query-filter-repeater` · `attachment-type`

**Leer la prop y escribir en el HTML del elemento.**

```php
add_action( 'elementor/frontend/before_render', function ( $element ) {
    $settings = $element->get_atomic_settings();
    if ( ! empty( $settings['karmcp_particles'] ) ) {
        $element->add_render_attribute( '_wrapper', 'data-karmcp-particles', $value );
    }
} );
```

`includes/base/element-base.php:500` dispara `elementor/frontend/before_render`
y `elementor/frontend/{$element_type}/before_render` **antes** de que el
elemento imprima su etiqueta de apertura
(`atomic-element-base.php:228`, que hace `print_render_attribute_string( '_wrapper' )`).
El hook recibe el elemento completo, así que ahí sí se pueden leer sus props y
añadir atributos o clases.

**El orden es el correcto, y no por casualidad.** El hook corre en la línea 500;
el elemento construye sus propios atributos en `add_render_attributes()`, que
`print_element()` llama después, en la 547. Como `add_render_attribute()`
acumula en lugar de sobrescribir, lo que añada la extensión sobrevive a lo que
el elemento añada luego — incluidas las clases, que se fusionan en un array.

### Lo que no se puede sin escribir React

**Añadir propiedades nuevas a la pestaña Style.** El filtro
`elementor/atomic-widgets/styles/schema` existe
(`modules/atomic-widgets/styles/style-schema.php:37`) y acepta propiedades
nuevas, pero el panel las pinta desde listas fijas del JavaScript empaquetado:

```js
// assets/js/packages/editor-editing-panel/editor-editing-panel.js:7685
fields: ['mix-blend-mode', 'box-shadow', 'opacity', 'transform', 'filter', 'backdrop-filter', 'transform-origin', 'transition']
```

Una propiedad añadida ahí sería válida, se guardaría y generaría CSS, pero
**nadie la vería en el editor**. Ni el core ni Pro usan ese filtro para añadir
propiedades: lo usan para que las existentes acepten variables
(`modules/variables/hooks.php:75`).

**Conclusión de diseño:** las extensiones viven en la pestaña de ajustes, no en
la de estilos. Un "text-shadow" visible en el panel de Style no entra en este
roadmap; requeriría un paquete de editor propio y es otro proyecto.

### Callejones sin salida ya explorados

No repetir estas dos investigaciones:

- **Los transformers de props no sirven para esto.** `Props_Resolver_Context`
  (`props-resolver/props-resolver-context.php`) solo lleva `key`, `prop_type` y
  `disabled`: **no lleva el elemento**. Desde un transformer no se pueden leer
  las otras props del elemento, así que no se puede decidir "si esta prop vale
  X, añade la clase Y".
- **El transformer de `attributes` está ocupado.** Solo hay uno por key y Pro
  registra el suyo (`modules/attributes/module.php:33`). Competir por esa key
  rompería los atributos personalizados de Pro.

De paso, un detalle que **no vamos a copiar**: el transformer de Pro concatena
sin escapar (`return $item['key'] . '="' . $item['value'] . '"';`).

### Nombres de elementos

Los identificadores se leen de `get_element_type()`, nunca se adivinan.
Verificados: `e-div-block` (`div-block.php:38`), `e-flexbox` (`flexbox.php:38`).
El resto sigue el mismo prefijo `e-`; el compilador debe validar contra la lista
real de elementos registrados, no contra una lista escrita a mano.

---

## 2. El spec v1

Mismo modelo que los otros dos compiladores: datos, no código. El agente declara
qué props añade, qué controles las editan y qué sale en el HTML; el plugin
compila y decide el escapado.

```jsonc
{
  "spec_version": 1,
  "meta": {
    "title": "Partículas",
    "description": "Añade un fondo de partículas animadas a un contenedor."
  },

  // A qué elementos se engancha. "*" = todos los atómicos.
  "targets": [ "e-div-block", "e-flexbox", "e-grid" ],

  // La sección que aparece en el panel de ajustes del elemento.
  "section": { "label": "Efectos KarMCP" },

  "props": [
    {
      "name": "karmcp_particles",          // debe empezar por karmcp_
      "type": "select",                     // select|switch|text|number|size|color|link
      "label": "Partículas",
      "default": "none",
      "options": { "none": "Ninguna", "snow": "Nieve", "stars": "Estrellas" }
    },
    {
      "name": "karmcp_particles_density",
      "type": "number",
      "label": "Densidad",
      "default": 40
    }
  ],

  // Qué sale en el HTML del elemento. Lo único que toca el front.
  "output": [
    {
      "when": { "prop": "karmcp_particles", "not": "none" },
      "class": "karmcp-fx-particles",
      "attributes": {
        "data-karmcp-particles": "{{karmcp_particles}}",
        "data-karmcp-density": "{{karmcp_particles_density}}"
      }
    }
  ],

  // El CSS se parte por cuándo puede llegar (ver §3): lo crítico va inline en
  // el render, el resto como archivo.
  "styles": {
    "critical": ".karmcp-fx-particles{position:relative;overflow:hidden}",
    "deferred": ".karmcp-fx-particles canvas{position:absolute;inset:0}"
  },
  "scripts": "/* lee data-karmcp-particles y dibuja */"
}
```

### Reglas del spec

| Regla | Motivo |
|---|---|
| `name` empieza por `karmcp_`, `^karmcp_[a-z][a-z0-9_]{0,31}$` | El schema de props es un espacio de nombres compartido con Elementor y con Pro. Sin prefijo, una colisión pisa una prop del core en silencio. |
| Los nombres son únicos entre **todas** las extensiones activas | El último filtro gana; dos extensiones con la misma prop sería un bug invisible. Lo valida el store al activar, no el compilador. |
| `targets` se valida contra los elementos realmente registrados | No adivinar nombres (la trampa de siempre con Elementor). |
| `when` solo admite `equals` / `not` / `truthy` sobre una prop declarada | Un mini-lenguaje de condiciones se convierte en un intérprete; con esto basta para el 100% de los casos reales. |
| Los valores de `attributes` solo interpolan `{{prop}}` de props declaradas | Igual que en las plantillas de widget: el texto literal es literal. |
| `class` pasa por `sanitize_html_class()` en compilación **y** el nombre se emite como literal | Una clase no debería poder venir del valor de un control. |
| `styles.critical` ≤ 2 KB | Va inline en cada elemento que use el efecto. Sin tope, se convierte en la puerta por la que todo el CSS acaba repetido en la página. |
| `styles` / `scripts` sin `<?`, con los límites del sandbox | Se sirven como archivos estáticos. |
| Los `options` del spec son un mapa; el compilador los traduce a la lista de pares que espera `Select_Control` | Que el spec sea cómodo de escribir y el código generado sea correcto son dos problemas distintos. |

### Tipos de prop soportados en la v1

Mapeo directo a los prop types y controles del core, que es lo que garantiza que
el editor sepa pintarlos:

| Tipo del spec | Prop Type de Elementor | Control |
|---|---|---|
| `switch` | `Boolean_Prop_Type` | `Switch_Control` |
| `select` | `String_Prop_Type` con `enum` | `Select_Control` |
| `text` | `String_Prop_Type` | `Text_Control` |
| `textarea` | `String_Prop_Type` | `Textarea_Control` |
| `number` | `Number_Prop_Type` | `Number_Control` |
| `size` | `Size_Prop_Type` | `Size_Control` |

**No hay `color`.** El catálogo de `controls/types/` no incluye ningún control de
color: en la 4.2 el color se edita desde la pestaña Style, no como ajuste. Un
efecto que necesite un color lo resuelve con `select` sobre una paleta suya, o
espera a que exista el control. `link` queda fuera de la v1 por su valor
compuesto.

---

## 3. Qué genera el compilador

Un único archivo PHP por extensión, en `wp-content/karmcp-sandbox/extensions/<id>/extension.php`,
con una clase que registra sus propios hooks. El loader la incluye tras verificar
el hash y la instancia.

```php
<?php
/**
 * GENERATED FILE — do not edit.
 * Compiled by KarMCP from an element extension spec.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'KarMCP_Extension_3260' ) ) {
	return;
}

class KarMCP_Extension_3260 {

	const TARGETS = array( 'e-div-block', 'e-flexbox', 'e-grid' );

	public function register_hooks(): void {
		add_filter( 'elementor/atomic-widgets/props-schema', array( $this, 'props' ) );
		add_filter( 'elementor/atomic-widgets/controls', array( $this, 'controls' ), 10, 2 );
		add_action( 'elementor/frontend/before_render', array( $this, 'render' ) );
	}

	public function props( array $schema ): array {
		$schema['karmcp_particles'] = \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type::make()
			->enum( array( 'none', 'snow', 'stars' ) )
			->default( 'none' );

		$schema['karmcp_particles_density'] = \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Number_Prop_Type::make()
			->default( 40 );

		return $schema;
	}

	public function controls( array $controls, $element ): array {
		if ( ! in_array( $element::get_element_type(), self::TARGETS, true ) ) {
			return $controls;
		}

		$controls[] = \Elementor\Modules\AtomicWidgets\Controls\Section::make()
			->set_label( 'Efectos KarMCP' )
			->set_items( array(
				\Elementor\Modules\AtomicWidgets\Controls\Types\Select_Control::bind_to( 'karmcp_particles' )
					->set_label( 'Partículas' )
					// Lista de pares, NO un mapa: el compilador traduce el
					// `options` del spec a esta forma (div-block.php:96).
					->set_options( array(
						array( 'value' => 'none',  'label' => 'Ninguna' ),
						array( 'value' => 'snow',  'label' => 'Nieve' ),
						array( 'value' => 'stars', 'label' => 'Estrellas' ),
					) ),
				\Elementor\Modules\AtomicWidgets\Controls\Types\Number_Control::bind_to( 'karmcp_particles_density' )
					->set_label( 'Densidad' ),
			) );

		return $controls;
	}

	public function render( $element ): void {
		if ( ! method_exists( $element, 'get_atomic_settings' )
			|| ! in_array( $element::get_element_type(), self::TARGETS, true ) ) {
			return;
		}

		$settings = $element->get_atomic_settings();
		$value    = (string) ( $settings['karmcp_particles'] ?? '' );

		if ( 'none' === $value || '' === $value ) {
			return;
		}

		$element->add_render_attribute( '_wrapper', 'class', 'karmcp-fx-particles' );
		$element->add_render_attribute( '_wrapper', 'data-karmcp-particles', esc_attr( $value ) );
		$element->add_render_attribute(
			'_wrapper',
			'data-karmcp-density',
			esc_attr( (string) ( 0 + (float) ( $settings['karmcp_particles_density'] ?? 0 ) ) )
		);

		wp_enqueue_style( 'karmcp-extension-3260-style' );
		wp_enqueue_script( 'karmcp-extension-3260-script' );
	}
}
```

Lo que hay que respetar al escribir el generador:

- **Todo valor del spec se emite con `var_export()`**, como en los otros dos
  compiladores. El texto del spec nunca se convierte en código.
- **Todo valor de una prop se escapa al emitirlo**, según su tipo: `esc_attr`
  para cadenas, cast para números. La clase CSS se emite como literal ya
  saneado en compilación.
- Los nombres de clase de Elementor se emiten **completamente cualificados**, sin
  `use`, para que el archivo no dependa del contexto donde se incluya.
- **Las opciones de un `select` son una lista de pares** `['value'=>…, 'label'=>…]`
  (`div-block.php:96`), no el mapa que usa el spec. La traducción la hace el
  compilador: el spec se escribe como le resulta natural al agente, y la forma
  que espera Elementor se genera.

### El encolado de assets: un compromiso, no una solución elegante

Los widgets tienen `get_style_depends()` y los bloques tienen el campo `style`
de `register_block_type()`: en ambos casos Elementor o WordPress encolan la hoja
justo cuando el artefacto aparece en la página. **Para las extensiones no existe
ese equivalente**, porque quien las usa es un elemento ajeno.

`elementor/frontend/before_render` corre durante `the_content`, con `wp_head` ya
impreso, así que un `wp_enqueue_style()` ahí acaba en el footer: funciona, pero
con un parpadeo de estilo sin aplicar. Las tres salidas, y por qué se elige la
tercera:

1. **Encolar siempre, en `wp_enqueue_scripts`.** Sin parpadeo, pero toda página
   del sitio carga el CSS de un efecto que casi ninguna usa.
2. **Encolar en el render.** Sin coste en las páginas que no lo usan, pero con
   parpadeo en las que sí.
3. **Registrar siempre, encolar en el render, y mantener el CSS crítico
   inline.** El compilador emite las reglas que evitan el parpadeo
   (`position`, `overflow`, dimensiones del contenedor) como `<style>` inline en
   el propio render, y deja en el archivo lo que puede llegar tarde sin que se
   note (animaciones, decoración).

La opción 3 es la que documenta este roadmap. Tiene una consecuencia que hay que
respetar en el spec: **`styles` se parte en dos**, `critical` e `deferred`, y el
validador limita el primero a algo pequeño (2 KB) para que no se convierta en la
puerta trasera por la que todo el CSS acabe inline en cada elemento.

---

## 4. Arquitectura en el sandbox

Reutiliza todo lo que ya existe. Lo único genuinamente nuevo son cuatro clases.

| Archivo | Qué es |
|---|---|
| `includes/sandbox/class-extension-spec.php` | Vocabulario (tipos de prop, mapeo a Elementor) y validación. Puro. |
| `includes/sandbox/class-extension-generator.php` | Compilador spec → PHP. Puro. |
| `includes/sandbox/class-extension-store.php` | `extends KarMCP_Sandbox_Store`. CPT `karmcp_extension`, manifest, CRUD, bundle. |
| `includes/sandbox/class-extension-loader.php` | Manifest + hash + aislamiento de fatales + kill switch. |
| `includes/abilities/class-extension-builder-abilities.php` | Las 8 herramientas MCP. |
| `includes/admin/views/sandbox/extensions.php` | Cuarta tarjeta y su pantalla. |

Se reutilizan sin tocar: `KarMCP_Sandbox_Paths`, `KarMCP_Sandbox_Store`,
`KarMCP_Sandbox_Bundle`, el export/import, el backup a la nube y el escáner
(el sandbox ya está excluido).

> **Trampa del bootstrap, otra vez.** El store y el loader se cargan y se
> instancian juntos, en el mismo commit. Ya pasó con los bloques:
> `class-bootstrap.php` instancia el loader en cuanto existe la clase del store.

`post_type` = `karmcp_extension` → 17 caracteres, dentro del límite de 20.

### Momento de carga

Los tres hooks se registran en `init`, como los otros loaders. `props-schema` y
`controls` se consultan cuando el editor pide la configuración de un elemento y
cuando se guarda; `frontend/before_render`, en cada render. Ninguno exige
adelantarse a `elementor/init`.

---

## 5. Seguridad

El modelo es el mismo que ya está probado en los otros dos compiladores, con dos
diferencias propias de esta clase de artefacto:

1. **El espacio de nombres de props es compartido.** Una extensión no puede
   declarar una prop sin el prefijo `karmcp_`, y el store rechaza activar una
   extensión cuyas props colisionen con las de otra activa. Es el equivalente a
   la verificación de nombre de clase de los widgets.
2. **El filtro `props-schema` se aplica a todos los elementos.** Una extensión
   mal escrita podría añadir props a elementos que no le interesan; el generador
   filtra por `targets` en `controls` y en `render`, pero el schema se declara
   igualmente. Es aceptable —una prop declarada y nunca usada es inerte— pero
   conviene documentarlo, porque explica por qué el schema de un elemento crece
   con extensiones activas.

El resto es lo de siempre: carga solo desde manifest con sha256, un fatal
desactiva la extensión culpable y no el sitio, activación por un humano, y un
filtro `karmcp_load_element_extensions` como interruptor global.

---

## 6. Herramientas MCP

Ocho, calcadas a las de los otros dos builders:

```
karmcp/list-element-targets      (read)  elementos disponibles + tipos de prop + sintaxis
karmcp/validate-extension-spec   (read)  dry-run del compilador real
karmcp/create-element-extension
karmcp/update-element-extension
karmcp/get-element-extension     (read)
karmcp/list-element-extensions   (read)
karmcp/set-extension-status
karmcp/delete-element-extension  (destructive, confirm:true)
```

`list-element-targets` debe devolver los elementos **realmente registrados** en
ese sitio, leyendo `get_element_type()` del registro de Elementor. Es lo que
evita que el agente escriba `div-block` en vez de `e-div-block`.

Todas se siembran deshabilitadas: `DEFAULTS_VERSION` sube y se añaden los 8
slugs, igual que la v3 y la v24 hicieron con widgets y bloques. **Sin marcarlas
`'pro'`** — ese error ya nos costó una release.

---

## 7. Tests

Lo que se puede probar sin WordPress, que es casi todo lo que importa:

- **Spec**: prefijo obligatorio, tipos desconocidos, `targets` vacío, opciones de
  select, condiciones `when` mal formadas, límites de tamaño.
- **Generador**: que el PHP generado parsea (`token_get_all` con `TOKEN_PARSE`,
  como en el block store); que un valor hostil en una opción no escapa del
  literal; que la clase CSS se sanea; que `targets` acaba en un `in_array`
  estricto; que una prop sin `karmcp_` no llega a compilarse.
- **Colisiones**: dos extensiones con la misma prop → la segunda no activa.

Lo que necesita un sitio real y hay que verificar a mano: que el control aparece
en el panel, que el valor sobrevive al guardado, y que el atributo sale en el
front. Es exactamente la lista que se verificó con los widgets y bloques en
sitionet.

---

## 8. Fases

| Fase | Contenido |
|---|---|
| **0** | **Prueba de concepto: 30 líneas en un snippet PHP que añadan una sección con un `Switch_Control` a `e-div-block`. Si la sección no aparece en el panel, este roadmap no se implementa como está escrito.** Ver §10. |
| 1 | Spec + generador + tests. Nada tocando WordPress. |
| 2 | Store + loader + bootstrap + uninstaller, en un commit. |
| 3 | Abilities + `DEFAULTS_VERSION` + catálogo de herramientas. |
| 4 | Cuarta tarjeta en Sandbox y su pantalla de gestión. |
| 5 | Verificación en sitionet con una extensión de partículas de verdad. |
| 6 | Release. |

Tamaño estimado: ~1.400 líneas de producción y ~400 de test, del mismo orden que
el Block Builder.

---

## 9. Lo que deliberadamente no hace

- **No añade propiedades a la pestaña Style.** Explicado en §1; requeriría un
  paquete React.
- **No toca elementos clásicos** (secciones, columnas, widgets 3.x). Solo 4.2+
  atómico, por decisión del proyecto.
- **No genera CSS por elemento.** El `styles` de la extensión es una hoja
  estática ligada a una clase. Generar reglas a partir de valores de control
  significa sanear CSS, que es un problema distinto del de sanear HTML y merece
  su propio diseño.
- **No usa el módulo `interactions`.** Existe
  (`elementor/atomic-widgets/interactions/schema`) y es la vía nativa para
  comportamiento en la 4.2, pero es joven y sin ejemplos de terceros. Cuando
  madure, es la evolución natural de la salida `attributes` + script.

---

## 10. La asunción que puede tumbar esto

Todo lo anterior está verificado leyendo el código **menos una cosa**, y conviene
que esté escrita sin adornos:

> **Que una `Section` añadida por el filtro `controls` se pinte en el panel sin
> que el plugin registre un paquete JavaScript propio.**

A favor: los controles del core (`Switch_Control`, `Select_Control`…) tienen su
componente React ya registrado en el editor, y el panel se construye desde lo
que PHP devuelve en `get_atomic_controls()`, no desde una lista fija — a
diferencia de la pestaña Style, donde sí la hay.

En contra: el único ejemplo de un plugin externo haciéndolo es Elementor Pro
(`akismet.php`), y Pro **sí** registra paquetes propios en el editor
(`elementor/editor/v2/packages`). No he podido descartar que algo de esa
maquinaria sea necesario para que la sección aparezca.

Por eso la fase 0 es una prueba de concepto de media hora y no un trámite: si la
sección no aparece, el coste del proyecto cambia de orden de magnitud y hay que
volver a decidir. **No escribir el compilador antes de resolver esto.**

## 11. Riesgo de fondo

Este roadmap se apoya en costuras internas de Elementor, no en una API pública
documentada. Los cuatro hooks son los que Elementor Pro usa para sus propias
funciones, lo cual es la mejor garantía disponible —si los rompen, se rompen a sí
mismos—, pero no es un contrato.

La mitigación es la que ya usa el resto del plugin: **si el hook desaparece, la
extensión no se registra y no pasa nada más**. El generador emite comprobaciones
(`method_exists`, `class_exists`) antes de tocar nada de Elementor, y el loader
aísla los fatales. Una extensión que deja de funcionar tras una actualización
debe degradarse a inerte, nunca a pantalla blanca.
