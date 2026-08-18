# Widgets de Unlimited Elements en uso

Los doce widgets de Unlimited Elements que usa content.karcos.com, con las claves
que llevan **de verdad**, leídas de instancias reales que un humano aprobó.

## Por qué existe este documento

El catálogo curado del plugin describe 62 widgets, todos de Elementor y su Pro.
Ninguno de estos doce está en él, y `get-widget-schema` con `full: true` no los
resuelve: sobre `ucaddon_item_menu` devuelve cientos de controles y **aun así le
faltan claves que la página usa y se ven en pantalla**. La introspección no
alcanza porque estos widgets construyen sus controles desde la definición del
addon, no desde el registro de Elementor.

Así que la fuente aquí no es la introspección: es la página. Cada ficha se ha
leído con `find-element` + `get-element-settings` sobre un post concreto, que se
cita. Todo lo que sigue es verificable volviendo a ese post; nada está deducido.

Seis de los doce no estaban descritos en ningún sitio, y entre ellos están el
segundo y el cuarto más usados del sitio.

| Widget | posts | leído de |
|---|---|---|
| `ucaddon_DynamicAssessmentPro` | 265 | 12168 · `b2b1906` |
| `ucaddon_barra_de_estado_y_progreso` | 249 | 12177 · `a3c01c6` |
| `ucaddon_item_menu` | 245 | 12177 · `cf79f7d` |
| `ucaddon_tema_colores` | 233 | 11168 · `031108a` |
| `ucaddon_Clasificar_6` | 67 | 12147 · `ca2cb21` |
| `ucaddon_ConexionColumnas` | 58 | 12017 · `f336d76` |
| `ucaddon_Ordenamiento` | 52 | 11333 · `f7899b2` |
| `ucaddon_completa_la_frase` | 50 | 12018 · `e00d42f` |
| `ucaddon_crucigrama` | 31 | 11502 · `0d496da` |
| `ucaddon_VideoQuiz` | 20 | 10565 · `0a48dea` |
| `ucaddon_video_interactivo` | 16 | 10689 · `7f0fa96` |
| `ucaddon_boton_especial` | 2 | 3360 · `4a8da0bb` |

---

## Lo que hay que saber antes de las fichas

### 1. La mitad de lo que ves en un widget no es del widget

Casi todas estas instancias llevan estas claves, y **ninguna pertenece al widget**:

| Clave | De quién es |
|---|---|
| `jedv_conditions` | JetEngine (visibilidad dinámica) |
| `pa_cursor_ftext`, `pa_badge_text` | Premium Addons |
| `premium_gradient_colors_repeater`, `premium_mscroll_repeater` | Premium Addons |

Se cuelan en todo elemento del sitio porque esos plugins inyectan sus controles
en todos los widgets. `pa_badge_text: "New"` y `pa_cursor_ftext: "Premium Follow
Text"` son sus valores de fábrica: si los ves, es que nadie los tocó. No los
copies al montar, y no te preocupes si aparecen.

### 2. Marcar la respuesta correcta se hace de cinco maneras distintas

Este es el detalle que más tiempo cuesta, porque lo aprendido en un juego no
sirve para el siguiente:

| Widget | Cómo se marca la correcta |
|---|---|
| `DynamicAssessmentPro` | `is_correct_2: "on"` — sufijo con el número de respuesta. **Admite varias por pregunta** |
| `VideoQuiz` | `correct_1: "true"` — cadena, no booleano |
| `Clasificar_6` | `correct_category: "1"` o `"2"` — a qué categoría pertenece |
| `completa_la_frase` | La palabra va **entre asteriscos** dentro de la frase: `elegir *tres* metricas` |
| `crucigrama` | No hay marca: cada fila es `word` + `clue` |

### 3. El mismo concepto tiene tres nombres según el widget

El color principal se llama:

- `accent_color` en `Clasificar_6`, `crucigrama` y `completa_la_frase`
- `color_accent` en `ConexionColumnas` y `VideoQuiz`
- `color_primary` en `DynamicAssessmentPro`

No hay forma de adivinarlo. Míralo aquí antes de escribirlo.

### 4. `uc_items` es siempre el contenido

Todos los juegos y los dos interactivos guardan su contenido en un repeater
llamado `uc_items`. Lo que cambia son las claves de cada fila. El `_id` de fila
es opcional: `Ordenamiento` y `ConexionColumnas` no lo llevan y funcionan.

### 5. El cuarteto de evaluación

`is_evaluable`, `passing_score`, `max_attempts` y `continue_action` aparecen en
`crucigrama`, `completa_la_frase`, `ConexionColumnas` y `VideoQuiz`. Valores
vistos: `is_evaluable: "true"` (cadena), `passing_score: "75"`,
`max_attempts: "3"`, y `continue_action` con `next-container` o `hidden`.

### 6. `__globals__` es un mapa aparte, y manda sobre el hex

La identidad de marca no viaja en las claves de color: viaja en un mapa
`__globals__` al mismo nivel que el resto de settings.

```
"color_primary": "#9E2AB5",
"__globals__": { "color_primary": "" }
```

Con la entrada a `""` el color queda desconectado del global y vale el hex. Con
`"globals/colors?id=0e4689f"` manda el global y el hex se ignora. Esto es lo que
hace posible el modo cliente: cambia el global y cambia el curso entero.

---

## Fichas

### `ucaddon_item_menu` — tarjeta de módulo de la portada

La tarjeta de cada módulo en la portada, y el enlace a su popup.

| Clave | Forma | Nota |
|---|---|---|
| `title`, `content`, `btn_text` | texto / HTML | `content` acepta `<p>` |
| `title_tag` | `h3` | |
| `jet_attached_popup` | id de post | **El item al que abre.** Sin esto la tarjeta no lleva a ninguna parte |
| `alignment` | `left` | |
| `show_image`, `show_badge` | `""` para ocultar | |
| `minimum_height` | `{unit, size}` | 260 px en la instancia |
| `box_border_radius`, `button_radius` | `{unit, size}` | 2 px / 48 px |
| **`border_top_width`** | `{unit, size}` | **72 px.** El filete superior de la tarjeta |
| **`border_top_height`** | `{unit, size}` | **5 px.** Su grosor |
| `content_padding`, `button_padding` | `{unit, top, right, bottom, left, isLinked}` | |
| `bg_color`, `border_color`, `title_color`, `text_color` | hex | |
| `button_background`, `button_color` | hex | |
| `button_background_hover`, `button_color_hover` | hex | |
| `button_border_border` | `none` | |
| `color_bloqueo`, `color_desbloqueo` | hex | Estado bloqueado / desbloqueado de la tarjeta |
| `title_typography_*`, `text_typography_*`, `button_typography_*` | grupo | Requieren `*_typography_typography: "custom"` |

`border_top_width` y `border_top_height` son las dos claves que el validador
marcaba como inexistentes hasta 1.25.1, ofreciendo `minimum_height` como
corrección. Existen, se ven, y son el filete de color de la parte de arriba de la
tarjeta.

### `ucaddon_barra_de_estado_y_progreso` — barra de progreso del curso

El segundo widget más usado del sitio, y el más pequeño de todos:

| Clave | Forma | Nota |
|---|---|---|
| `altura_de_la_barra` | `{unit, size, sizes, value}` | Forma anómala: lleva `size: 16` y `value: "10"` a la vez |
| `color_fondo_barra_color` | **solo por `__globals__`** | En la instancia va a `globals/colors?id=0e4689f` |

Todo lo demás que aparece en su JSON es equipaje de otros addons. Si buscas más
palancas, no las hay: este widget se controla casi entero por el global de color.

### `ucaddon_tema_colores` — paleta del curso

Cuatro colores y nada más. Es el punto por el que un curso entero cambia de
identidad:

`theme_primary`, `theme_secondary`, `theme_text`, `theme_accent` — todos hex.

### `ucaddon_boton_especial`

`link_text`, y el resto por globales: `background_color` a
`globals/colors?id=primary` y `button_typography_typography` a
`globals/typography?id=primary`. Solo 2 posts lo usan.

### `ucaddon_DynamicAssessmentPro` — cuestionario final

El widget más usado del sitio. Cabecera: `label_question`, `label_attempts`,
`label_result_default`, `msg_attempts_remaining`, `btn_verify_text`,
`btn_next_text`, `btn_finish_text`, `btn_review_text`, `btn_restart_text`,
`msg_success_title`, `msg_success_text`, `msg_fail_title`, `msg_fail_text`,
`msg_score_detail`, `msg_no_attempts`, `color_primary`, `color_success`,
`color_error`, `randomize_questions`.

Marcadores de plantilla en los mensajes: `%n%` (intentos restantes), `%correct%`
y `%total%` (aciertos sobre total).

Fila de `uc_items`: `_id`, `question_text`, `answer_1`..`answer_4`,
`is_correct_N: "on"` por cada correcta (**varias admitidas**), `feedback_pos`,
`feedback_neg`.

### `ucaddon_Clasificar_6` — clasificar en dos categorías

`cat_1_name`, `cat_2_name`, `accent_color`, `label_check`, `label_retry`,
`label_continue`, `txt_attempts_prefix`, `txt_status_correct`,
`txt_status_incorrect`, `txt_status_unclassified`, `txt_try_again_general`,
`main_aria_label`, `aria_description`, `typography_head_*`.

Fila: `_id`, `item_title`, `item_image` (`{url, id, size}`), `alt_text`,
`correct_category` (`"1"` o `"2"`).

Lleva accesibilidad propia: `main_aria_label` y `aria_description` describen cómo
se juega con teclado. Merece la pena rellenarlas.

### `ucaddon_ConexionColumnas` — emparejar dos columnas

`color_accent`, `color_secondary`, `color_success`, `color_error`,
`passing_score`, `max_attempts`, `is_evaluable`, `continue_action`.

Fila: `text_left`, `text_right`. Sin `_id`. El emparejamiento correcto es el de
la propia fila.

### `ucaddon_Ordenamiento` — ordenar pasos

El más simple de los seis: `title`, y filas con `title` + `alt_text`. **El orden
correcto es el orden en que se escriben.** No lleva colores ni cuarteto de
evaluación.

### `ucaddon_completa_la_frase` — hueco en la frase

`accent_color`, `color_secondary`, `passing_score`, `max_attempts`,
`is_evaluable`, `continue_action`.

Fila: `sentence_text` con la palabra correcta **entre asteriscos**, y `alt_text`
con la frase resuelta sin marcas.

### `ucaddon_crucigrama`

`accent_color`, `bg_color`, `color_success`, `color_error`, `max_attempts`,
`passing_score`, `enable_retry`, `is_evaluable`, `continue_action`,
`main_aria_label`, `aria_description`, `label_check`, `aria_label_check`,
`label_horizontal`, `label_vertical`, `label_view_results`, `label_final_score`,
`label_continue`, `txt_attempts_prefix`, `txt_min_score_prefix`,
`msg_error_exist`, `msg_error_empty`, `msg_success`, `msg_fail`,
`label_review_mode`.

Fila: `_id`, `word`, `clue`. Las palabras van **en mayúsculas y sin tildes** — la
propia `aria_description` de la instancia se lo advierte al alumno.

### `ucaddon_VideoQuiz` — preguntas sobre un vídeo

`video_url`, `cover_video` (`{url, id, size, alt, source}`), `label_verify`,
`label_continue_video`, `label_retry`, `label_continue`, `msg_success`,
`msg_fail`, `continue_action`, `color_correct`, `color_incorrect`,
`color_accent` (por global).

Fila: `_id`, `trigger_time` (**segundo del vídeo en que salta la pregunta**),
`pregunta_texto`, `res_1`..`res_3`, `correct_N: "true"`, `feedback_pos`,
`feedback_neg`.

### `ucaddon_video_interactivo` — varios vídeos encadenados con pregunta

Etiquetas: `label_replay`, `label_check`, `label_continue`, `label_retry`,
`texto_progreso`, `texto_nota_final`, `label_attempts`, `label_score`,
`label_next_video`, `msg_no_video`, `label_required`, `label_current_score`,
`label_left_pre`, `label_left_post`, `msg_success`, `msg_fail`, `msg_retry`.
`accent_color` va por global.

Fila: `_id`, `video_url`, `question`, `opt_1`..`opt_3`, `feedback_ok`,
`feedback_err`.

Ojo: aquí la pregunta se llama `question` y las opciones `opt_N`, mientras que en
`VideoQuiz` son `pregunta_texto` y `res_N`. Son dos widgets distintos que hacen
casi lo mismo con vocabularios distintos.

---

## Cómo se actualiza esto

Se relee. Cuando Unlimited Elements se actualice, o cuando aparezca un widget
nuevo, se repite el procedimiento sobre una instancia real:

```
find-element         { post_id: <un post que lo use>, widget_type: "<tipo>" }
get-element-settings { post_id: <ese post>, element_id: "<el que salga>" }
```

Y para saber qué posts usan qué:

```sql
SELECT post_id FROM wp_postmeta
WHERE meta_key = '_elementor_data' AND meta_value LIKE '%<tipo>%' LIMIT 1;
```

Este documento es también el conjunto de prueba de la librería de widgets
mapeados por instancia, si algún día se construye: lo que el descubrimiento
automático produzca tiene que coincidir con lo que hay aquí.
