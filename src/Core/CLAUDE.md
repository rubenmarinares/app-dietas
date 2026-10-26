# src/Core — micro-framework

Clases transversales. Ninguna conoce el negocio. Si añades algo aquí, que sirva a cualquier pantalla.

## Clases

| Clase | Responsabilidad | Por qué existe / gotchas |
|---|---|---|
| `App` | Ejecuta la petición entera en su constructor: sesión, `Request`, dispatch FastRoute, gate de auth, gate de permisos, invoca `Screen::metodo(...$vars)`. Guarda `$db`, `$user`, `$messages`, `$userpermissions`, `$currentmodule`. | Sustituye a un framework. **No hay middleware**: reglas transversales van aquí. `PUBLICSCREENS` = screens sin login (hoy solo `LoginScreen`); las públicas no pasan por permisos. `setUser()` quita `password` y regenera el id de sesión. `addmessage()` acepta `['type','text']` o string. |
| `ScreenBase` | Base de las Screens. `rendertemplate($tpl, $data, $echo=true)` monta Twig e inyecta globales. `render_json()`. | **El `Twig\Environment` se crea en cada render** (loader, extensiones, funciones): simple pero caro; si el CMS público necesita rendimiento, cachearlo estático. Caché compilada en `src/cache/views/` (debe existir y ser escribible). Con `$echo=true` consume los mensajes flash. |
| `TwigMyExtensions` (en `ScreenBase.php`) | Filtros: `monedaatexto`, `monedaatextous`, `monedaaentero`, `dateinttofrontend`, `datesqltofrontend`, `datetoint`, `datesqltoint`, `json_decode`, `truncate`, `filesizetotext`, `urlencodepreserveslash`. | Al añadir uno, añádelo también a `tools/twig_lint.php`. `TwigFiltersTest` comprueba que cada filtro apunta a un método existente. |
| `Database` / `db` | Singleton `db extends PDO` con atajos: `run()` (SELECT→filas, DML→rowCount), `runone()` (primera fila o `[]`), `insert($t,$info)`, `update($t,$info,$where,$bind)`, `delete($t,$where,$bind)`, `select()`. | `insert/update` filtran columnas con `DESCRIBE` (columna inexistente = se ignora). `PDO::ATTR_CASE => LOWER` y `STRINGIFY_FETCHES`: **todo llega como string en minúsculas**. Errores: `error_log` y `false`; solo se imprimen con `APP_DEBUG`. Si la conexión falla lanza `RuntimeException`. Con `APP_DEBUG` se envuelve en `TraceablePDO` (DebugBar). |
| `ModelBase` | CRUD genérico guiado por `$GLOBALS['dbschema']`: `get`, `search($db,$filters)`, `insert`, `update`, `upsert`, `delete`, `exists`, `count`, `getascombo`, `getcombos`. Filtros `{mode: EQUAL…BETWEEN, field, table, values}`. `$joins`, `$combos` (añade `campo_label`), `$defaultorder`. | Ver `src/Domain/Models/CLAUDE.md`. Filtros inseguros se descartan y se reportan (`$onviolation` / `error_log`), nunca 500. |
| `Session` | `get/set/delete/destroy` sobre `$_SESSION`. | **Tras `destroy()` la sesión ya no está activa: cualquier `set()` posterior se pierde** (causa del bug de login 2FA heredado). Para deshacer algo concreto usa `delete()`. |
| `Request` | Método, URI, path, headers, GET/POST/body. `getRealMethod()`. | `X-HTTP-Method-Override` solo se acepta sobre POST hacia PUT/PATCH/DELETE; nunca rebaja a GET (saltaría el permiso de escritura). |
| `Csrf` | `token()` (estable por sesión), `validate($t)` (`hash_equals`), `rotate()`. | Único mecanismo CSRF. Las plantillas reciben `csrf_token`; el campo se llama `csrf_token`. |
| `TokenGenerator` | Tokens URL-safe (`generate()`) y `hash()` sha256. | Para enlaces de un solo uso: guarda el hash, no el token. |
| `Mailer` | `send($email,$nombre,$asunto,$html,$texto)` vía PHPMailer/SMTP (`SMTP_*`). | Devuelve `false` en error (no lanza): el Domain decide si es crítico. |
| `Storage` / `FileSystem` | Flysystem 3 con discos `local` (`<raíz>/files` o `<raíz>/<bucket>`), `ftp` (`STORAGE_FTP_*`), `s3` (`S3_*`). | Opcional. Las credenciales salen de constants (.env). |
| `S3FILE` | `save()`/`get()` directos contra S3. | Legado; preferir `Storage::disk('s3')`. |
| `PDF` | `PDF::print($html, $ruta='')` con mPDF (autor = `APP_NAME`). | Renderiza el HTML con `rendertemplate(..., false)`. |
| `functions.php` | Helpers globales: fechas `yyyymmdd` (`hoy()`, `fechanumeroatexto()`, `fechatextoanumero()`…), dinero ×10000 (`monedaenteroatexto()`, `monedatextoaentero()`), CSV, `filter_filename()`. | Sin namespace; cargado en Bootstrap. |

## Variables globales en plantillas (inyectadas por `rendertemplate`)

`APP_NAME`, `PUBLIC_PATH`, `APP_PATH`, `PUBLIC_UPLOAD_PATH`, `messages`, `logado`, `menu`, `post`, `get`, `today` (yyyymmdd), `thisyear`, `currentuser` (fila de users sin password), `csrf_token`.
Funciones Twig: `can(modulo=actual, nivel='write')`, `print_checkeliminar()`, `debugbar_header()`, `debugbar()`. Test: `is array`.

## Gate de permisos (App)

1. Módulo = `PermissionDomain::modulefromclass(Screen)` (+ `MODULEALIASES`).
2. Si el módulo está en `PermissionDomain::ALWAYSALLOWED` (`index`, `userprofile`) pasa siempre.
3. Nivel requerido = `'perm'` de la ruta o GET/HEAD→`read`, resto→`write`.
4. Permisos del usuario = `typesuser.permissions` leído **en cada petición** (un cambio aplica al instante).
5. Denegado → `users_logs` acción `denied` y 403 (JSON si `Accept: application/json` o XHR).
