# Roadmap: la pestaña Optimize

> Continuación de lo entregado en **KarMCP 1.11.0** (pestaña Optimize + `clean-database`).
> Escrito el 2026-08-15 contra el árbol real. Si algo no cuadra con el código, gana el código.
>
> Regla del apartado, y conviene no perderla: **esto no cachea**. El cacheo esconde trabajo lento
> detrás de una copia guardada; aquí se quita trabajo que WordPress hace para nada. Es la velocidad
> que sobrevive a un vaciado de caché, y la que un plugin de caché no puede dar.

## Lo que ya existe (1.11.0)

`includes/performance/class-db-cleaner.php` + `includes/admin/views/page-optimize.php` +
`karmcp/clean-database`. Seis tareas: revisiones, transients caducados, metadatos huérfanos, spam y
papelera de comentarios, entradas en papelera, y `OPTIMIZE TABLE`. Cada una declara `breaks` y
`reversible`; cuatro son permanentes y lo dicen antes del botón. Borrados en lotes de
`KarMCP_DB_Cleaner::BATCH` (300).

**El hueco de fondo:** todo eso *limpia*, nada *previene*. Dentro de un mes el sitio vuelve a estar
igual, y la pestaña queda como una escoba en un sitio que no deja de ensuciarse.

---

## Pieza A — Prevención

**Por qué va primero.** Es barata, es reversible, y sin ella lo demás se deshace solo.

- **Tope de revisiones** por el filtro `wp_revisions_to_keep` (no `WP_POST_REVISIONS`, que es de
  `wp-config.php` y por tanto está fuera de alcance — mismo razonamiento que llevó a
  `DISALLOW_FILE_EDIT` a ser una constante definida en runtime en `harden-site`).
- **Retención de papelera**: filtrar los días antes del vaciado automático.
- **Heartbeat**: bajar la frecuencia de `admin-ajax` o apagarlo fuera del editor. Con varios editores
  abiertos es CPU constante y continua.

Todo esto son interruptores que el plugin posee, como los de `harden-site`: se apagan desde la misma
casilla que los encendió.

**Esfuerzo** medio día.

## Pieza B — Antes y después, y limpieza programada

Hoy pulsas *Clean selected* y no ves si sirvió de algo — exactamente el problema que tenía la
puntuación de seguridad antes del desglose.

- Guardar tamaño de base y filas por tabla antes y después, y mostrarlo.
- Cron semanal para las tareas **no destructivas** (transients, huérfanos, `OPTIMIZE`). Las
  permanentes nunca automáticas: el patrón de `KarMCP_Security_Monitor::init()` sirve tal cual.

**Esfuerzo** medio día.

## Pieza C — Opciones autocargadas

**El mayor retorno real de toda la lista.** Se leen en cada petición, incluida cada llamada AJAX.
WordPress recomienda quedarse por debajo de 800 KB; muchos sitios van por 3-4 MB.

- Informe: las 20 mayores con tamaño y a qué plugin pertenecen por prefijo, más el total.
- Apagar el autoload **de una concreta**, con confirmación y reversible.
- Subconjunto más seguro y de valor inmediato: **opciones huérfanas de plugins desinstalados**.

**No automatizar el apagado masivo.** Apagar el autoload en la opción equivocada rompe el plugin que
la puso, y ninguna regla sabe cuáles son. Esa decisión es de una persona — la misma línea que ya se
trazó con `harden_admin_user` en seguridad.

**Esfuerzo** 1-2 días.

## Pieza D — Limpieza del cron

Vencidos que nunca corren, duplicados, y sobre todo **fantasmas**: eventos que apuntan a hooks sin
callback porque el plugin que los registró ya no está. Cada spawn los recorre para no hacer nada.
Seguro, medible, y el auditor de rendimiento ya detecta el atasco.

**Esfuerzo** medio día.

## Pieza E — Peso del front

Casillas simples y reversibles: quitar el script de emojis, `wp-embed.js`, y los estilos de bloques
en un sitio que va 100% Elementor. Kilobytes y una petición menos por cada uno.

**Cuidado:** los estilos de bloques rompen cualquier página que sí use Gutenberg. Detectarlo antes de
ofrecerlo.

**Esfuerzo** medio día.

## Pieza F — Índices que faltan

En una base con mucho `postmeta` —y con JetEngine instalado, sitionet lo es— un índice sobre
`meta_key, meta_value(191)` puede llevar consultas de segundos a milisegundos.

Alto impacto y **reversible** (se puede quitar el índice), pero hay que medir antes: en una tabla
grande, crear el índice bloquea, y en un sitio pequeño no compensa. Informar del tamaño de la tabla y
del tiempo estimado antes de ofrecer el botón.

**Esfuerzo** 1-2 días, la mitad en medir con honestidad.

## Pieza G — Coste por plugin

Cuánto añade cada plugin al TTFB. Es lo que la gente quiere saber de verdad y lo que ningún plugin
gratuito hace bien.

Requiere instrumentar con un mu-plugin que marque tiempos alrededor de la carga de cada plugin y
promediar sobre varias peticiones. Es trabajo real, no una tarde, y la atribución nunca es del todo
limpia — un plugin que registra un hook caro carga el coste en quien dispara el hook.

**Esfuerzo** 3-5 días. La más valiosa y la más cara.

---

## Orden

| # | Pieza | Esfuerzo | Nota |
|---|---|---|---|
| A | Prevención | Medio día | Sin esto, lo demás se deshace |
| B | Antes/después + cron | Medio día | Cierra el bucle de 1.11.0 |
| C | Opciones autocargadas | 1-2 días | Mayor retorno real |
| D | Limpieza de cron | Medio día | Seguro y medible |
| E | Peso del front | Medio día | Casillas simples |
| F | Índices | 1-2 días | Medir antes de ofrecer |
| G | Coste por plugin | 3-5 días | El más valioso y el más caro |

**A y B juntas son una release pequeña** y convierten la pestaña en algo que se sostiene solo.

## Lo que no debe entrar aquí

- **Cacheo.** Otra capa, y hay plugins que lo hacen bien. Si entra, esta pestaña deja de tener una
  idea que la ordene.
- **Borrar tablas de plugins desinstalados.** Reconocerlas por el nombre es adivinar, y adivinar mal
  borra datos reales. Está escrito en la propia pantalla y debe seguir estándolo.
- **Minificar y combinar CSS/JS.** Es territorio de los plugins de caché y romperlo es trivial.
