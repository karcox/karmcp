# Roadmap: el apartado de Seguridad

> **Documento de diseño, escrito para implementarse desde una conversación nueva.** Todo lo que aquí
> se afirma sobre el árbol actual está verificado fichero a fichero el 2026-08-15, no recordado. Si
> algo no cuadra con el código, gana el código — y corrige este documento.
>
> Objetivo del conjunto: que KarMCP **sustituya a Wordfence** en sitionet.com y en el resto de sitios,
> y que un agente pueda no solo detectar sino **actuar**.

## Cómo usar este documento

Cada pieza trae: qué es, por qué, **dónde encaja en el código que ya existe**, qué hay que escribir,
qué se testea y qué puede salir mal. El orden del final no es decorativo: cada pieza apoya a la
siguiente.

### ⚠️ sitionet.com es el banco de pruebas — NO actualizar nada allí

**Los plugins desactualizados de sitionet.com son deliberados: son la fixture con la que se desarrolla
y se valida este apartado.** Es un dominio de pruebas, no un sitio en producción que haya que proteger.

Medido el 2026-08-15 contra el feed real: **53 vulnerabilidades activas repartidas en 12 plugins**,
con SQL injection sin autenticar, inyección de objetos PHP, XSS almacenado y CSRF a RCE, y rangos de
CVSS de 4.3 a 8.8. Es un juego de pruebas mucho mejor que cualquier sitio limpio: cubre severidades
altas y bajas, plugins con parche disponible y plugins sin él, y versiones de cuatro componentes
(`3.5.6.1`) que ejercitan el comparador de rangos.

**No ejecutar `update-plugin` ni `harden-site` contra sitionet sin pedirlo.** Actualizar destruiría la
fixture. Si algún escaneo devuelve esa lista, es la salida esperada, no una incidencia.

Antes de tocar nada, leer [CLAUDE.md](../CLAUDE.md). Las trampas que más tiempo cuestan aquí:

- **`'category' => 'karmcp'` es obligatorio** al registrar una ability. Si falta,
  `wp_register_ability()` la descarta **en silencio** — sin error, sin aviso, la herramienta no existe.
- **Las abilities se registran en `wp_abilities_api_init`, antes de que los módulos arranquen en
  `init:5`.** Por eso el registrar consulta el **estático** `is_enabled()` del módulo, nunca su instancia.
- **Los stubs compartidos van en `tests/bootstrap.php`**, no en el fichero de test: el bootstrap carga
  primero y un stub declarado en un test aplica o no según el orden de carga.
- **Toda herramienta que escribe** exige subir `DEFAULTS_VERSION` en `class-admin.php` (hoy `37`) y
  sembrar su slug como deshabilitada, además de una entrada en el catálogo de la pestaña Tools.

Suite: 544 tests, verdes. Ahora también en CI (Pieza 0). En Windows sin Composer:

```bash
PHPDIR=$(dirname "$(which php)")
php -d extension_dir="$PHPDIR/ext" -d extension=mbstring ../phpunit-10.phar
```

---

## Estado actual, verificado

**Lo que ya existe** — `includes/security/`, 1.153 líneas:

| Fichero | Qué hace |
|---|---|
| `class-security-scanner.php` (252) | Orquesta, agrega y puntúa. `ALL_CHECKS = ['malware','integrity','hardening','software']`. `CRITICAL_WEIGHT = 20`, `WARNING_WEIGHT = 5`, con un tope de penalización por categoría (`CATEGORY_CRIT_CAP`). Nota A-F |
| `class-security-finding.php` (41) | `Finding::make( id, category, label, status, value, message, recommendation )`. `status` ∈ `pass\|warning\|critical\|info` |
| `class-security-integrity-audit.php` (104) | Checksums oficiales de wordpress.org vía `get_core_checksums()`. `diff()` es **puro** y testeado; `run()` es la mitad viva |
| `class-security-malware-audit.php` (427) | Heurísticas PHP. **Ensambla sus patrones en runtime a propósito**: una firma íntegra en disco la pone en cuarentena el antivirus del hosting, que deja el fichero a cero y produce un fatal lejísimos del origen |
| `class-security-hardening-audit.php` (149) | Editor de ficheros, debug, usuario `admin`, XML-RPC, versión revelada, HTTPS, cabeceras. **Solo informa** |
| `class-security-software-audit.php` (180) | Desactualizados y abandonados. `evaluate_core_update()` ya detecta el core viejo. Docblock: *"No external CVE DB"* |

**Lo que no existe** — verificado con búsquedas que devuelven cero:

- **Ninguna pantalla de seguridad en el admin.** Hay 14 vistas en `includes/admin/views/` y ninguna es
  de seguridad; `includes/admin/` **no referencia ni una vez** al `Security_Scanner` ni al auditor de
  rendimiento. Los dos informes existen solo como salida MCP.
- **Nada de `fatal-error-handler`, `mu-plugins`, `paused_plugins` ni `recovery_mode`.**
- **Ninguna herramienta de actualización del core.** Cero ocurrencias de `Core_Upgrader` o `update-core`.
- **Ninguna base de vulnerabilidades.**
- ~~**Ningún CI.**~~ **Resuelto en la Pieza 0**: `.github/workflows/` tiene `tests.yml` (bloqueante,
  PHP 8.1-8.4) y `lint.yml` (PHPCS + PHPStan, aún no bloqueante). La cadena vive en `tools/`.

---

## Pieza 0 — CI y análisis estático ✅ HECHA (2026-08-15)

**Por qué va primero.** El repo es privado: nadie de fuera va a encontrar un bug nunca. El único
revisor que queda es la automatización, y hoy no hay ninguna. La prueba está en esta misma sesión:
`upload-media` se revisó, se escribieron 13 tests, se auditó — y **dos defectos solo aparecieron al
ejecutarlo contra un sitio real**. Sin CI, la siguiente release puede deshacer en silencio cualquier
cosa que endurezcamos aquí.

**Qué escribir**

- `.github/workflows/tests.yml` — PHPUnit en cada push y PR. La suite corre en 0,2 s y **no necesita
  WordPress**, así que el workflow es `composer install` + `vendor/bin/phpunit`. Matriz PHP 8.1–8.4.
- `.github/workflows/lint.yml` — PHPCS con `WordPress-Extra` + PHPStan con
  `szepeviktor/phpstan-wordpress`.
- `phpcs.xml.dist` y `phpstan.neon.dist` con **baseline**. En 75.352 líneas propias habrá mucho ruido:
  el objetivo es *no añadir deuda nueva*, no limpiar todo el día uno.

**Riesgo** Ninguno para producción; es todo fuera del plugin distribuido.

**Esfuerzo** ~1 hora el CI de tests. Medio día lint + baseline.

---

## Pieza 1 — Pestaña Security en el admin ✅ HECHA (KarMCP 1.5.0)

**Por qué.** Un escáner que solo corre cuando alguien pregunta **no es monitorización**. Un CVE sale
un martes; si esa semana no abres sesión con el agente, no te enteras. Es exactamente la mitad que se
pierde al quitar Wordfence.

**Dividendo importante:** la pestaña no es para el módulo de vulnerabilidades. Saca a la luz las
**cuatro categorías que ya existen** y hoy son invisibles en WordPress. Vale la pena aunque nunca se
haga la Pieza 4.

**Qué escribir**

- `includes/admin/views/page-security.php` — siguiendo el patrón de las 14 vistas existentes. Compacta,
  no un clon de Wordfence: puntuación y nota del último escaneo, fecha, hallazgos críticos y
  *warnings*, botón **Escanear ahora**, y por cada hallazgo de `hardening` un botón **Arreglar** que
  llama a la Pieza 2.
- Ruta de la pestaña en `class-admin.php` (mismo patrón que las otras).
- **Escaneo programado por WP-Cron.** El precedente ya está en el árbol:
  `KarMCP_OAuth_Server` agenda un GC diario con `wp_schedule_event`. El resultado se guarda en una
  opción (el informe resumido es pequeño; el feed de la Pieza 4 **no** va aquí, ver allí).
- **Aviso**: admin notice en críticos, opcionalmente correo. `KarMCP_Notifications` existe pero sirve
  para anuncios remotos del producto — **no vale tal cual**; su maquinaria de leído/no leído y el badge
  sí se reaprovechan.

**Regla de seguridad que no es negociable**

> Un escaneo que falló o unos datos viejos **jamás pueden renderizarse como "estás limpio"**. Si el
> feed no pudo refrescarse o el escaneo no terminó, la pantalla lo dice con claridad y muestra la
> antigüedad del dato. Confundir *"no sé"* con *"no hay nada"* es el fallo que acaba con alguien
> comprometido creyéndose a salvo.

**Tests** El render es difícil de testear sin WordPress. Lo testeable y que hay que testear: la
lógica de *frescura* (dado un timestamp y un TTL, ¿el dato es válido, viejo o desconocido?) como
función pura.

**Esfuerzo** 2-3 días con cron y avisos.

---

## Pieza 2 — `harden-site` ✅ HECHA (KarMCP 1.5.0)

**Por qué.** `hardening` hoy detecta y recomienda, pero no arregla. Cerrar ese bucle **no necesita
ninguna llamada saliente, ningún tercero y ninguna clave**: son datos que el plugin ya calcula.

**Qué escribir**

- `includes/security/class-security-hardening-fixer.php` — un *fixer* por cada `id` de hallazgo que
  produce `class-security-hardening-audit.php`. Cada uno declara si es aplicable, qué haría y cómo se
  revierte.
- `includes/abilities/class-security-fix-abilities.php` → ability `karmcp/harden-site`.
- **Dry-run por defecto**, como `build-site`: devuelve el plan y solo escribe con `apply:true` y
  `confirm:true`.
- **Reversible por el ledger.** Los cambios de opciones van por
  `KarMCP_Change_Recorder::record_options()`, que ya existe; los de fichero por `record_file()`.
- Incluye **tapar la fingerprint del `readme.txt`**: hoy
  `https://sitionet.com/wp-content/plugins/karmcp/readme.txt` lo sirve cualquiera y la línea 6 dice
  `Stable tag: 1.3.1`. Comprobado. Es así como los escáneres masivos fingerprintan plugins.

**Cuidado** Algunos endurecimientos rompen cosas legítimas: desactivar XML-RPC rompe Jetpack y la app
móvil; las cabeceras de seguridad pueden romper embeds. Cada fixer declara **qué puede romper** y eso
aparece en el dry-run.

**Registro** Es una herramienta que escribe → subir `DEFAULTS_VERSION`, sembrarla deshabilitada, y
añadirla al catálogo de la pestaña Tools.

**Tests** Cada fixer, puro: entrada de estado → plan de cambios. Sin WordPress.

**Esfuerzo** 1-2 días.

---

## Pieza 3 — Drop-in `fatal-error-handler` y recuperación

**Por qué.** Cuando WordPress peta, la REST API muere, y con ella MCP: el agente no puede ayudar justo
cuando más falta hace. Y una corrección a lo que suele creerse — **el Recovery Mode de WP 5.2 no
arregla el sitio solo**: manda un correo con un enlace y pausa el plugin *para esa sesión de
recuperación*; el visitante sigue viendo el error.

WordPress soporta un *drop-in*: si existe `wp-content/fatal-error-handler.php`, el core lo carga en
lugar de su manejador. Es código nuestro corriendo **en el momento del fatal**.

**Qué escribir**

- La plantilla del drop-in en `includes/security/` y un instalador que lo copie a `wp-content/`
  (con backup si ya hay uno de otro plugin — **no pisarlo sin avisar**).
- En el fatal: identificar el fichero culpable → mapearlo a su plugin → registrar el evento
  estructurado en un fichero bajo `wp-content/uploads/` con su `.htaccess` de denegación (patrón que
  `KarMCP_Filesystem_Guard` ya usa, ~línea 191).
- **Autodesactivación con freno**: solo tras N fatales del mismo plugin en M minutos, y con una
  **lista de intocables** configurable (WooCommerce, el tema activo, KarMCP). Un fatal transitorio no
  puede tumbarte la tienda por otra vía.
- Abilities: `karmcp/get-fatal-log`, `karmcp/list-paused-plugins`, `karmcp/resume-plugin`.

**Cuidado** El drop-in corre en contexto de fatal: memoria posiblemente agotada, WordPress a medio
cargar. Tiene que ser **mínimo, defensivo y sin dependencias** — nada de autoloader, nada de clases
del plugin. Si el drop-in falla, el sitio queda peor que sin él.

**Tests** El mapeo fichero → plugin y la lógica de reincidencia (N en M) son puras. El drop-in en sí
se prueba a mano provocando un fatal en un plugin de juguete en local.

**Esfuerzo** 1-2 días.

---

## Pieza 4 — `update-core`

**Por qué.** Hoy el core viejo solo se detecta (`evaluate_core_update()`), no se actualiza.

**Qué escribir** `find_core_update()` + `Core_Upgrader`, el mismo `Package_Guard::filesystem_ready()`
que ya usan plugin y tema, `confirm:true`, deshabilitada por defecto.

**El riesgo no es el código, es que KarMCP corre dentro de WordPress**: actualizar el core es cambiar
el suelo estando de pie encima, en mitad de la petición REST que ese core está sirviendo. Por eso esta
pieza **va después de la 3**: con el manejador de fatales puesto, si la actualización rompe algo el
sitio levanta solo.

**Esfuerzo** Medio día de código.

---

## Pieza 5 — Módulo de vulnerabilidades

**Por qué.** Es lo que de verdad sustituye a Wordfence. Hoy sabes *"JetFormBuilder 3.5.6.1 está
desactualizado"*; quieres *"CVE-XXXX, subida sin autenticar, CVSS 9.8, parcheado en 3.6.0"*.

### La fuente

Ver [[wordfence-intelligence-feed]] en memoria. Lo esencial, verificado el 2026-08-15:

- `GET https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production`
- `Authorization: Bearer <clave>` — **único esquema válido**; Basic, `Token` y `?k=` dan 401
- **v1 y v2 devuelven 410 Gone.** Toda la documentación que circula (incluido el blog de Wordfence de
  2022) describe un acceso libre sin registro que **terminó el 9 de marzo de 2025**
- Gratis para uso personal **y comercial**. Clave en *cuenta de wordfence.com → Integrations*
- **Usar Production, no Scanner**: el Scanner no trae `cve`, `cvss`, `cwe` ni `description`

### Tres hechos que fijan la arquitectura

1. **Los endpoints devuelven el feed completo y no aceptan parámetros.** No existe consulta por
   plugin. La caché local no es una preferencia: es la única forma. De regalo, nunca le revelas a
   Wordfence qué corres.
2. **Hay límite de cuota, sin documentar y sin cabeceras.** Empíricamente: una petición buena y la
   siguiente ya da 429, y seguía dando 429 quince minutos después. No hay `Retry-After` ni
   `X-RateLimit-*`. Probablemente diaria. → refresco por WP-Cron **una vez al día**, backoff
   exponencial, y **nunca** bloquear una carga de página esperando al feed. Más cuota se pide a
   `wfi-support@wordfence.com` justificándola.
3. **La licencia obliga a atribuir.** Defiant concede licencia perpetua e irrevocable para reproducir
   y redistribuir **a condición de** incluir un hipervínculo al registro y reproducir su aviso de
   copyright y licencia en cualquier copia; MITRE exige lo equivalente para los CVE. **La pestaña
   Security debe mostrar el aviso y enlazar al registro en cada hallazgo.** No es cortesía.

### Qué escribir

- `includes/modules/vulnerabilities/` como **módulo** (`KarMCP_Module` + registro en
  `KarMCP_Modules_Registry`, opción `karmcp_active_modules`), **apagado por defecto**: llama fuera.
- **Interfaz de proveedor**, no acoplamiento a Wordfence. Durante la investigación de este documento
  se encontró un endpoint documentado como vivo que devolvía 410. Volverá a pasar. Que cambiar de
  fuente sea escribir una clase, no reescribir el módulo:
  ```php
  interface KarMCP_Vulnerability_Provider {
      public function fetch_feed();               // array|WP_Error
      public function attribution( array $rec );  // aviso + enlace exigidos por la licencia
  }
  ```
### Tamaño real del feed — medido el 2026-08-15

| | |
|---|---|
| Descarga (gzip) | **11,2 MB** en ~1,8 s |
| Descomprimido | **150,87 MB** |
| Registros de vulnerabilidad | **38.701** |
| Entradas software (slug × vuln) | 42.057 |
| Slugs distintos | 18.106 — 15.960 plugins, 2.144 temas, 2 core |
| Índice `slug → [ids]` solo | 2,2 MB |

**Esto invalida el plan ingenuo de "guardar el JSON y decodificarlo".** `json_decode()` de 150 MB
necesita del orden de 1 GB de memoria PHP: imposible en un `memory_limit` normal, y desde luego en
hosting compartido. **El feed no se puede cargar entero en memoria, nunca.**

**Arquitectura que sí funciona:**

1. Descargar a disco **comprimido** (11 MB) en `wp-content/uploads/` con `.htaccess` de denegación —
   patrón que `KarMCP_Filesystem_Guard` ya usa (~línea 191).
2. **Parseo incremental.** La raíz es un objeto `{"uuid": {…}, "uuid": {…}}`, así que se recorre el
   stream contando llaves —respetando cadenas y escapes— y se hace `json_decode()` **de un registro
   cada vez**. La memoria se queda en un registro, no en 150 MB. El troceador es **lógica pura**: se
   testea con un JSON de juguete, sin WordPress y sin red.
3. Volcar a **tabla propia** solo lo que se usa: `slug`, `type`, rangos de versión, `cve`, `cvss`,
   `patched_versions`, título y enlace. 42.000 filas no son nada para MySQL, y consultar por slug pasa
   a ser un índice en vez de recorrer un fichero.
4. Borrar el `.gz` tras procesarlo. No hace falta conservarlo.

El paso 2 es el que hay que escribir con cuidado; el resto es rutina.
- `class-security-vulnerability-audit.php` → **quinta categoría `vulnerability`**, añadida a
  `Scanner::ALL_CHECKS`. El hueco ya existe: es una lista y `Finding::make()` es uniforme.
- Ability `karmcp/list-vulnerabilities`.
- **Vigilar también `vendor/`**: el zip transporta 301 ficheros PHP de terceros
  (`mcp-adapter`, `jetpack-autoloader`, `enshrined/svg-sanitize`). Ese último sanea los SVG que se
  suben: un bypass ahí es XSS almacenado en la biblioteca de medios. Con Wordfence fuera, nadie más lo
  vigila.

### El emparejamiento de versiones

Cada registro trae `software[]` con `slug`, `type` y `affected_versions` como rangos con
`from_version` / `to_version` y flags `from_inclusive` / `to_inclusive`. Comparar la versión instalada
contra esos rangos es **lógica pura** → tests unitarios sin WordPress, al estilo de
`KarMCP_Guardrails_Policy`. Casos que hay que cubrir: rango abierto por un extremo, límites
inclusivos y exclusivos, versiones con sufijo (`3.5.6.1`, `1.0.0-beta2`), y `patched: false`.

**Efecto colateral a asumir:** una quinta categoría cambia la puntuación. Los scores dejan de ser
comparables con los de versiones anteriores. Documentarlo en el changelog.

**Esfuerzo** 3-5 días el módulo. La pestaña de la Pieza 1 ya está hecha para entonces.

---

## Pieza 6 — Aplicar parches desde la pestaña

Tres niveles, y solo dos son automatizables.

### Nivel 1 — El parche de verdad: actualizar

En la mayoría de casos "aplicar el parche" **es actualizar el plugin**, y las dos mitades ya existen
sueltas: el feed da `patched_versions` y `remediation`; `karmcp/update-plugin` funciona. Nadie las ha
conectado. Un botón **Actualizar** junto a cada hallazgo es el mayor retorno del módulo entero.

**Se apoya en la Pieza 3**: actualizar sin red es arriesgado; con el manejador de fatales detrás, si
el plugin peta el sitio levanta solo.

### Nivel 2 — Sin parche disponible: contener

Con `patched: false` no hay nada que actualizar. Botón **Desactivar** (`deactivate-plugin`, ya existe)
y `harden-site` para reducir el radio.

### Nivel 3 — Mitigación quirúrgica: asistida, nunca automática

La técnica legítima es un **mu-plugin que desactive la pieza vulnerable en runtime** — un
`remove_action()` sobre el handler afectado, o un filtro que añada la comprobación de capacidad que
falta. Sobrevive a las actualizaciones y **no toca código ajeno**.

No se puede automatizar: el feed dice *que* hay vulnerabilidad, no *qué hook* desenganchar. Pero aquí
KarMCP tiene un ángulo que ningún escáner tiene — **puede leer los ficheros del sitio**. Un agente
puede leer el CVE, leer el código del plugin, localizar el handler y escribir la mitigación. Como
asistencia supervisada, con dry-run y reversible por el ledger. No como feature automática.

### Lo que NO se hace

- **WAF / interceptar peticiones.** Capa equivocada; mal hecho es peor que nada.
- **Editar el código del plugin de terceros.** `edit-file` técnicamente puede, con backup. La
  siguiente actualización lo pisa y queda un sitio que nadie entiende.
- **Redistribuir reglas de mitigación ajenas.** La licencia de Defiant cubre los *datos*, no reglas de
  firewall. Las de Patchstack son su producto de pago.

### Excepción del Package Guard para Elementor — **decidido**

`Package_Guard::is_protected_plugin()` bloquea hoy KarMCP, Elementor y Elementor Pro en las **tres**
operaciones: desactivar (`class-plugin-abilities.php:324`), actualizar (`:371`) y borrar (`:445`).

**Decisión: se permite actualizar Elementor y Elementor Pro cuando hay una vulnerabilidad conocida que
afecta a la versión instalada.** No es un permiso general.

- Desactivar y borrar **siguen bloqueados siempre**.
- **KarMCP no se actualiza a sí mismo nunca**: reemplazar tu propio código a mitad de una petición no
  es lo mismo que actualizar una dependencia.
- La excepción **se gana, no se afirma**. El módulo debe confirmar las tres cosas: (a) la versión
  instalada cae en un rango de `affected_versions`, (b) `patched: true`, (c) la actualización
  disponible es ≥ alguna de `patched_versions`. Si no, actualizar no arregla nada y habrías saltado el
  guard para nada.
- **Sin acoplar el guard al módulo**, que es opcional y va apagado. El guard expone un filtro y el
  módulo lo engancha — el mismo idioma que `karmcp_before_write` usa con Guardrails:
  ```php
  // En Package_Guard, en la ruta de actualización únicamente.
  $allow = apply_filters( 'karmcp_allow_protected_update', false, $file );
  ```
  Por defecto `false` = comportamiento de hoy. El módulo devuelve `true` solo con las tres
  condiciones cumplidas.
- **Siempre con `confirm:true` explícito.** Nunca automático para un plugin protegido.

> **Aviso que hay que enseñar en la pantalla.** `Plugin_Upgrader` instala lo que ofrezca el transient
> de actualizaciones, que es **la última versión**, no la mínima parcheada. En sitionet eso significa
> que una excepción de seguridad sobre Elementor saltaría de **3.34.4 a 4.2.2** — un cambio de major
> con Elementor Pro 3.31.2 → 4.2.1 detrás. La pantalla debe mostrar el salto tal cual antes de
> confirmar, y la Pieza 3 debe estar puesta.

---

## Pieza 7 — Endurecimiento del login ✅ HECHA (KarMCP 1.4.0)

**Por qué.** De todo lo que se pierde al quitar Wordfence, esta es la única mitad que **sí** tiene
sentido absorber en el plugin. No es un WAF: no necesita inteligencia de amenazas ni reglas mantenidas
por nadie. Es contar intentos fallidos y aplicar bloqueo temporal. Está acotado, es lógica pura, y es
**el ataque más frecuente que recibe cualquier WordPress**.

El WAF se queda fuera a propósito (ver Decisiones abiertas): va delante, en Cloudflare o en el
hosting, no dentro de un plugin PHP que arranca tarde y que en un sitio con LiteSpeed solo vería los
*cache miss*.

**Qué escribir**

- `includes/security/class-login-guard-policy.php` — **puro**, al estilo de
  `KarMCP_Guardrails_Policy`: dados N fallos con sus marcas de tiempo, ¿esta IP o este usuario están
  bloqueados, y hasta cuándo? Sin `get_option()` ni `current_time()` dentro; los datos se le pasan.
- `includes/security/class-login-guard.php` — engancha `wp_login_failed`, `authenticate` y
  `wp_login`, y aplica la política.
- Bloqueo **con escalado y siempre acotado en el tiempo**. Nunca permanente: un bloqueo permanente por
  IP acaba dejándote a ti fuera.
- Conteo **por IP y por nombre de usuario** por separado. Solo por IP no frena un ataque distribuido
  contra una cuenta; solo por usuario permite barrer usuarios desde una IP.
- Cortar la **enumeración de usuarios**, que es la mitad de reconocimiento del ataque:
  `/wp-json/wp/v2/users` y las redirecciones `?author=N`. Ojo: la enumeración por REST tiene usos
  legítimos autenticados — cerrar solo la vía anónima.
- **XML-RPC como amplificador**: `system.multicall` permite probar cientos de contraseñas en una sola
  petición. La auditoría de `hardening` ya detecta XML-RPC expuesto; aquí es donde se cierra.
- Almacenamiento: los intentos fallidos son datos de alta rotación. Va en **tabla propia** —el plugin
  ya crea cinco— con limpieza por el patrón de GC que `KarMCP_OAuth_Server` ya usa (`wp_schedule_event`
  diario).
- Superficie en la pestaña Security (Pieza 1): bloqueos activos, intentos recientes, y un botón para
  desbloquear una IP.

### La trampa de la IP detrás de Cloudflare

**Esto hay que hacerlo bien desde el principio o el módulo es peor que no tenerlo.** Detrás de
Cloudflare, `REMOTE_ADDR` es la IP de Cloudflare, no la del visitante: **todos tus usuarios comparten
IP**, así que a los cinco fallos de cualquiera bloqueas el mundo entero.

La corrección es leer `CF-Connecting-IP` o `X-Forwarded-For` — pero esas cabeceras **las puede
falsificar cualquiera** si no vienen de un proxy en el que confías, y entonces el atacante rota su IP
aparente a voluntad y el bloqueo no sirve de nada.

Regla: **por defecto `REMOTE_ADDR`**, y la cabecera solo se usa cuando el administrador declara
explícitamente el proxy y su rango en la configuración. `KarMCP_Url_Guard` ya tiene una tabla de
rangos reservados y formas IPv6 que sirve para validar eso.

Es directamente relevante: Cloudflare entra en el plan.

### No romper la autenticación de MCP

El guard no puede dejar fuera al propio agente. La ruta OAuth ya tiene su propio límite por IP en
`/register` desde la 1.2.0 — hay que ser coherente con él y **excluir explícitamente** los endpoints
del servidor MCP del conteo de login, o un cliente que reintenta se autobloquea.

**Fuera de alcance** 2FA. Se hace bien en plugins dedicados, e implementar TOTP con códigos de
recuperación en condiciones es un proyecto propio, no un apartado de este.

**Tests** Toda la política, pura: escalado, expiración, conteo por IP frente a por usuario,
resolución de IP con y sin proxy declarado.

**Esfuerzo** 2-3 días.

---

## Orden

| # | Pieza | Esfuerzo | Depende de |
|---|---|---|---|
| ~~0~~ | ~~CI + análisis estático~~ **hecha** | — | — |
| ~~1~~ | ~~Pestaña Security + cron + avisos~~ **hecha (1.5.0)** | — | — |
| ~~2~~ | ~~`harden-site`~~ **hecha (1.5.0)** | — | — |
| 3 | Drop-in de fatales + 3 abilities | 1-2 días | — |
| 4 | `update-core` | Medio día | 3 |
| 5 | Módulo de vulnerabilidades | 3-5 días | 1 |
| 6 | Parcheo desde la pestaña | 1-2 días | 1, 3, 5 |
| ~~7~~ | ~~Endurecimiento del login~~ **hecha (1.4.0)** | — | superficie pendiente en 1 |

Total: **tres semanas largas** de trabajo real.

**Dos condiciones de salida antes de desinstalar Wordfence:** la Pieza 5, o te quedas sin datos de
vulnerabilidad; y la Pieza 7, o te quedas sin protección de fuerza bruta. El WAF va aparte, ver abajo.

La 7 no depende de nada, así que puede adelantarse: si el objetivo inmediato es poder apagar
Wordfence, el camino más corto es **0 → 7 → 1 → 5**.

## Decisiones abiertas

1. **El WAF — decidido: fuera del plugin, delante.** Va en **Cloudflare**, que Carlos añadirá.
   Queda escrito el porqué para no re-litigarlo: un WAF en PHP arranca *después* de que WordPress se
   haya inicializado (Wordfence solo lo evita con `auto_prepend_file`, es decir, dejando de ser un
   plugin); en un sitio con LiteSpeed cache solo vería los *cache miss*; las reglas son el producto de
   pago de Wordfence y Patchstack y no se pueden reutilizar —la licencia de Defiant cubre los datos,
   no las reglas—; un falso positivo rompe el checkout de WooCommerce en silencio, con peor radio de
   explosión que la mayoría de las vulnerabilidades que evitaría; y un WAF tiene que parsear entrada
   no confiada **antes** de que WordPress sanee nada, o sea que añade superficie de ataque dentro de
   lo que protege.
   **Hasta que Cloudflare esté delante, esa capa está descubierta.** Comprobar primero qué trae ya el
   hosting: LiteSpeed y la mayoría de gestionados llevan ModSecurity, puede que la pieza ya esté
   puesta sin saberlo.
2. **Cuota de Wordfence — medida, y compatible.** El feed se descargó con éxito ~50 minutos después
   del primer 429, así que la ventana está en el orden de la hora, no del día. Un refresco diario cabe
   de sobra. Los números del feed están arriba, en la Pieza 5. Si algún día hiciera falta más cuota, se
   pide a `wfi-support@wordfence.com` justificándola.
3. **Deuda de seguridad ya identificada, fuera de estas piezas.** Los scopes OAuth se normalizan al
   emitirlos y **nadie los lee** (`KarMCP_OAuth_Bearer::permission_callback()` no mira la columna);
   hoy es inocuo porque solo existe `mcp`. Y hay que auditar qué secretos van cifrados con
   `KarMCP_Secret` y cuáles viven en opciones en claro — claves de Pexels/Unsplash/Pixabay,
   credenciales del gateway Cloud.
4. **Rotar la clave de Wordfence** usada en las pruebas: pasó por una conversación.
