# Verificación en vivo de `upload-media` — sitionet.com

> **1.3.0 verificado en real el 2026-08-15.** Los seis casos se ejecutaron contra sitionet.com y dos
> destaparon defectos, corregidos en 1.3.1. Lo que queda pendiente es reverificar esos dos casos
> sobre 1.3.1 una vez instalada. Borra este fichero cuando eso esté hecho.

## Qué se ejecutó (sobre 1.3.0)

| # | Caso | Resultado |
|---|---|---|
| 1 | PNG 2×2 (73 bytes) con `alt` y `title` | **OK** — `attachment_id: 3226`, `mime_type: image/png`, `width/height: 2`, `filesize: 73`, `post_parent: 0`, URL servida |
| 2 | `list-media` buscándolo | **OK** — aparece con su alt intacto |
| 3 | PHP renombrado a `.jpg` | **Rechazado**, pero con el mensaje equivocado → defecto, ver abajo |
| 4 | `payload.php` | **OK** — `disallowed_file_type`, antes de decodificar |
| 5 | `post_id: 999999999` | **Rechazado**, pero con el mensaje equivocado → defecto, ver abajo |
| 6 | Ledger + rollback | **OK** — entrada `create-post` reversible; tras el rollback el attachment desapareció de la biblioteca y de `wp_posts` |

O sea: la ruta real de subida funciona, el contenido se verifica de verdad, y la subida es
reversible. Lo que falló fue **cómo se cuentan dos de los rechazos**, no si rechazan.

## Los dos defectos (corregidos en 1.3.1)

**Caso 5 — "Permission denied" a secas para un id inexistente.** `map_meta_cap()` resuelve
`edit_post` contra un post que no está a `do_not_allow`, así que preguntar por la capacidad antes de
comprobar la existencia convertía un id mal escrito en un problema de permisos. Además dejaba
*inalcanzable* la rama `post_not_found` del ejecutor. Es la misma costura del `false` pelado que la
1.2.1 cerró en `update-post` y `delete-post`, reabierta por la herramienta nueva.

**Caso 3 — el rechazo llegaba en español, de core.** El mensaje era *"Lo siento, no tienes permisos
para subir este tipo de archivo."*: habla de permisos cuando el problema es el contenido, y depende
del idioma del sitio, así que ningún cliente puede razonar sobre él. Ahora se verifica antes con
`wp_check_filetype_and_ext()` y se devuelve `content_type_mismatch` con nombre y extensión. La
comprobación de WordPress sigue corriendo después: esa es la garantía, la nuestra es la explicación.

De paso: la pista `convert_webp:false` se añadía a *todos* los fallos, incluidos aquellos en los que
ese flag no puede cambiar nada.

## Pendiente sobre 1.3.1

Reinstalar y **reconectar el servidor MCP** (el catálogo de herramientas se fija al conectar), y
repetir solo estos dos:

- `filename: fake.jpg`, `data: PD9waHAgZWNobyAic2hvdWxkIG5ldmVyIGJlIHN0b3JlZCI7ID8+`
  → debe dar `content_type_mismatch` nombrando `fake.jpg` y `.jpg`, en inglés, sin hablar de permisos.
- `filename: orphan.png`, `data: iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z9ABRAwQCgAsrgYdhRJ3kwAAAABJRU5ErkJggg==`, `post_id: 999999999`
  → debe dar `post_not_found` nombrando el id, no "Permission denied".

El PNG del caso 1 sirve igual si quieres repetir el positivo:
`iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z9ABRAwQCgAsrgYdhRJ3kwAAAABJRU5ErkJggg==`
