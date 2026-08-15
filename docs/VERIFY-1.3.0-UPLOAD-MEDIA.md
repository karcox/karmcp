# Verificación en vivo de `upload-media` (1.3.0) — sitionet.com

> Nota de traspaso. La herramienta se implementó y se instaló en la misma sesión, pero no se pudo
> ejecutar en ella: el cliente MCP fija su catálogo de herramientas al conectar, así que
> `karmcp-upload-media` no era invocable hasta reconectar. Este fichero existe para que la sesión
> siguiente pueda rematarlo sin reconstruir el contexto. **Bórralo cuando esté verificado.**

## Estado al cerrar la sesión del 2026-08-15

Verificado contra el servidor, no asumido:

- `wp-content/plugins/karmcp/karmcp.php` → `Version: 1.3.0`.
- El fichero desplegado `includes/abilities/class-media-library-abilities.php` contiene
  `'karmcp/upload-media'` en `get_ability_names()`.
- La opción `karmcp_disabled_tools` tiene 24 slugs y **ninguno** es `upload-media`: está habilitada.
- Modo dispatcher apagado, así que la herramienta se expone individualmente.

Lo que **no** está verificado: que `media_handle_sideload()` haga su trabajo por esta vía. Los tests
unitarios (`tests/MediaUploadTest.php`) cubren la delegación de permisos, el resolver del nombre de
fichero y el decodificador del payload — todo lo que pasa **antes** de que un byte llegue al disco.
La subida real no la ha ejecutado nadie.

El sitio es `cfd3ae1a-4ae3-4053-a527-4d6a80f7daec` (SitioNet, https://sitionet.com).
Corre Elementor 3.34.4 + Pro 3.31.2, WooCommerce, y **PublishPress Capabilities** — relevante porque
el fix de 1.2.1 sobre `update-global-colors` / `update-global-typography` va justo de eso.

## La batería

Solo los casos 1 y 3 ejercitan código que no ha corrido nunca. El resto ya está cubierto por los
tests unitarios y se repite en real para confirmar que el cableado es el que se cree.

### 1. Caso positivo — PNG 2×2 (73 bytes)

```
filename: probe-upload-media.png
data:     iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z9ABRAwQCgAsrgYdhRJ3kwAAAABJRU5ErkJggg==
alt:      Prueba de upload-media 1.3.0
title:    Probe upload-media
```

Esperado: `attachment_id` > 0, `url` accesible, `mime_type: image/png`, `width: 2`, `height: 2`,
`post_parent: 0`, y `filename` de vuelta (puede venir deduplicado si el nombre ya existía).

### 2. Confirmar que está en la biblioteca

`list-media` con `search: "upload-media"` → debe salir con su alt.

### 3. Caso negativo de contenido — PHP disfrazado de `.jpg`

```
filename: fake.jpg
data:     PD9waHAgZWNobyAic2hvdWxkIG5ldmVyIGJlIHN0b3JlZCI7ID8+
```

Esperado: **error**, y que venga de `media_handle_sideload()` al leer el contenido — no de la
extensión, que aquí es legítima. Es el caso que demuestra que el chequeo autoritativo está en su
sitio. Si esto se almacena, hay un agujero.

### 4. Caso negativo de extensión

`filename: payload.php`, cualquier `data` → `disallowed_file_type`, **antes** de decodificar.

### 5. Padre inexistente

`post_id: 999999999` → `post_not_found`.

### 6. Ledger

**KarMCP → Changes** debe listar la subida del caso 1 con rollback disponible. Al hacer rollback, el
attachment y sus ficheros deben desaparecer (`wp_delete_post()` delega en `wp_delete_attachment()`
para attachments — comprobado leyendo core, no ejecutado).

## Limpieza

Borrar el attachment del caso 1 (o deshacerlo desde el ledger, que además prueba el punto 6).
