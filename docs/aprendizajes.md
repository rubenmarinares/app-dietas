# Aprendizajes de admin.redgdps

Errores reales encontrados al extraer este esqueleto, por qué ocurrieron y cómo evitarlos. Todos los marcados ✅ están corregidos aquí.

## Reglas que salen de estos errores

1. **Validar antes de implementar**: nombre exacto de tabla/columna en `dbschema.php`, existencia del método (`grep "function x"`), existencia de la plantilla/layout. La mitad de los bugs de abajo son llamadas a cosas que no existen.
2. **dbschema "solo añadir"**: al regenerar o editar no se pierden propiedades fijadas a mano (`maincol`, `primary`, `ordercol`, `default`, `type json`). Perder `maincol` rompe combos y orden; perder `primary` hace que `update()` actualice sin WHERE útil; perder `json` devuelve strings donde el código espera arrays. Nunca borrar sin revisión humana.
3. **Migraciones idempotentes**: se reaplican todas en cada deploy. Un `UPDATE … CURDATE() + INTERVAL 2 MONTH` movería el plazo cada vez; por eso 007/015 usaban fechas fijas.
4. **Continuidad de diseño**: al tocar una plantilla, partir de la versión anterior (`git log -p`) y no "simplificar" cards ni clases.
5. **Un test por cada bug**: los de abajo tienen su test en `tests/` cuando es posible.

## Bugs heredados (y corrección)

| Bug | Por qué pasó | Corrección |
|---|---|---|
| ✅ `LoginScreen` llamaba a `$session->delete()`, que no existía. | Se usó una API supuesta sin comprobarla. | `Session::delete()` añadido + `SessionTest`. |
| ✅ **El login con 2FA no podía funcionar**: se abría sesión, se hacía `session_destroy()` y luego se guardaba `2fa_pending` en una sesión ya destruida (se perdía). | Desconocer que tras `session_destroy()` los `$_SESSION[...] =` no se persisten. | Login en fases: `verifycredentials()` no abre sesión; `completelogin()` solo tras el 2FA. |
| ✅ 2FA por email en el login nunca enviaba el código. | La fase 2 asumía que el código ya existía. | `LoginScreen` llama a `send2FAEmail()` al entrar en la fase 2 si el método es email. |
| ✅ Contraseñas en **md5 sin sal**. | Código antiguo. | `password_hash` + migración transparente en el login (`User::login`). Test `AuthLoginTest`. |
| ✅ Guardar "Mi perfil" con la contraseña vacía guardaba `md5('')`. | `UserDomain::save` recibía `password => ''` y el Model la hasheaba. | El Model ignora contraseñas vacías; `UserDomain::changepassword` valida. |
| ✅ La sesión guardaba la fila entera de `users`, **incluido el hash**. | `setUser($row)` sin filtrar. | `App::setUser` y `User::login/search` eliminan `password`. |
| ✅ `login.html` enviaba `{{ csrf }}` (variable inexistente) y nadie validaba. | Plantilla copiada de otro proyecto. | `csrf_token` global en todas las plantillas y validación en Login, Settings, Mi perfil y 2FA. |
| ✅ Las plantillas `profile/*` extendían `layout/base.html`, que no existe. | Se generaron siguiendo una doc (Screens/CLAUDE.md) que describía una estructura imaginaria (`$this->csrf`, `currentUser`, `base.html`). | Extienden `layout.html` con el diseño de cards. Docs reescritas desde el código real. |
| ✅ `TwoFactorEmailModel::delete` pasaba un array como `$where` a `Database::delete` (espera string). | Firma de `Database::delete` no comprobada. | `'userid = ?', [$userid]`. |
| ✅ `SettingsDomain::user_delete` usaba `TypeBudgetLine::get` para obtener el usuario. | Copia-pega entre métodos `*_delete`. | Usa `User::get`. Test `SettingsDomainTest`. |
| ✅ Usuarios nuevos sin `deadline2fa` → no podían entrar nunca (sin 2FA y sin plazo). | El plazo solo se daba con migraciones de fecha fija. | `UserDomain::save` concede `TWOFA_GRACE_DAYS` en el alta; `create_admin.php` igual. |
| ✅ `IndexScreen` redirigía a `/login` y `/index` absolutos, ignorando `APP_PATH`. | Rutas escritas a mano. | Siempre `APP_PATH . 'ruta'` (o `$app->redirect`). |
| ✅ Filtro Twig `json_decode` apuntaba a `JsonDecode` pero el método era `JonDecode`; `monedaaentero` apuntaba a `',monedaaEntero'`. | Errata sin test. | Corregido + `TwigFiltersTest` comprueba que todos los filtros son invocables. |
| ✅ `monedaaEntero` multiplicaba la parte entera por 100 en vez de 10000 ("1,5" → 5100). | Mezcla de convenciones ×100 / ×10000. | ×10000 coherente con `monedaatexto`. Test. |
| ✅ `datesqltofrontend` devolvía solo el día (precedencia de `??` con `.`). | `$a ?? '' . '/' . $b` se evalúa como `$a ?? ('' . '/' . $b)`. | Paréntesis. Test. |
| ✅ `Request::validate()` instanciaba `App\Core\Validator`, que no existe. | Código muerto nunca ejecutado. | Eliminado. |
| ✅ `X-HTTP-Method-Override` permitía convertir un POST en GET: el gate exigía solo lectura y la Screen procesaba el `$_POST`. | El override se aceptaba sin restricciones. | Solo POST → PUT/PATCH/DELETE. |
| ✅ `Csrf::validate(?string)` lanzaba `TypeError` (500) si el token llegaba como array (`csrf_token[]=x`). | Tipado estricto sobre un valor que viene directo de `$_POST`. | Acepta `mixed` y rechaza lo que no sea string. Test en `CsrfTest`. |
| ✅ Un tipo de usuario sin `*` recibía 403 hasta en la portada y en "Mi perfil". | El gate no distinguía pantallas personales. | `PermissionDomain::ALWAYSALLOWED = ['index','userprofile']`. |
| ✅ Mensajes flash: `addmessage("texto")` se pintaba vacío (la plantilla espera `.type/.text`), se mostraban solo si la hija no sobrescribía el block y nunca se borraban. | Sin contrato definido. | `addmessage` normaliza, el layout los pinta fuera del block y `rendertemplate` los consume. |
| ✅ `Setting` usaba `$default_order` (ModelBase lee `$defaultorder`) y el fallback generaba `ORDER BY key DESC` (palabra reservada). | Nombre de propiedad no verificado. | `$defaultorder = 'settings.\`key\` ASC'`. |
| ✅ `ModelBase::validate` hacía `return true` al encontrar la PK autoincrement, saltándose la validación del resto; accedía a `['required']` sin `??`. | Atajo mal colocado. | `continue` y `?? false`. |
| ✅ `ModelBase::insert` con UUID: `$uuid==generateUUID()` (comparación, no asignación). | Errata. | `=`. |
| ✅ `ModelBase::count` leía `['total']` de un `SELECT COUNT(*)` sin alias. | Errata. | `COUNT(*) AS total`. |
| ✅ `array_map('static::enrichvalues', …)`: callable en string deprecado en PHP 8.2. | Sintaxis antigua. | `[static::class, 'enrichvalues']`. |
| ✅ `Database` hacía `echo` del SQL y del error (fuga de información) y `die` en inserts fallidos; si la conexión fallaba seguía con un PDO sin inicializar. | Código de depuración que llegó a producción. | `error_log`, `echo` solo con `APP_DEBUG`, `RuntimeException` al conectar. |
| ✅ DebugBar siempre activa (también en producción). | Sin flag de entorno. | Solo con `APP_DEBUG=1`. |
| ✅ `layout.html` tenía un `</div>` de más y `foot.html` cerraba `</body></html>` (duplicado). | Ediciones acumuladas. | HTML equilibrado. |
| ✅ `user_edit.html` marcaba `selected` con `in` (`"1" in "11"` es true). | Uso incorrecto del operador `in` de Twig. | `==`. |
| ✅ `constants.php` con credenciales y email personal; `.env.example` hablaba de `FTP_PASS` mientras `deploy.php` leía `FTP_PASSWORD`. | Configuración mezclada con código. | `constants.php` versionado leyendo `env()`; `.env.example` con todas las claves y `FTP_PASSWORD`. |
| ✅ `migrations/apply.php --test` tenía la BBDD de test hardcodeada (`amin_redgdps_test`, con errata). | Valor por defecto a mano. | `TEST_DBNAME` obligatorio desde `tests/.env.local`. |
| ✅ `DbSchemaValidityTest` estaba en `tests/Unit` pero necesita BBDD; `tests/bootstrap.php` abría conexión siempre (los unit fallaban sin MySQL). | Sin separación clara. | Movido a `Integration/`; conexión de test bajo demanda y *skip* si no hay BBDD. |
| ✅ `regen_dbschema.php` reescribía `dbschema.php` entero, perdiendo `ordercol`, `default` y `maincol` puestos a mano. | Generador "desde cero". | Modo "solo añadir" + `--dry-run`. |
| ✅ `league/flysystem` fijado a `3.0` (con aviso de seguridad) y `spomky-labs/otphp` 10.x (aviso; además `minimum-stability: dev` instalaba una rama dev). | Versiones congeladas sin revisar. | `^3.0`, `^11.4.3` y `minimum-stability: stable`. `composer audit` limpio. |

## Corregidos tras el proyecto redGDPS 2026 (octubre de 2026)

Encontrados al llevar a producción un CMS construido sobre este esqueleto (auditoría de seguridad y paso a producción). Cada uno con su test.

| Bug | Por qué pasó | Corrección |
|---|---|---|
| ✅ **Sin límite de intentos en el login ni en el 2FA**: con una contraseña robada, el código TOTP se adivinaba por fuerza bruta en horas (≈1,7 M intentos/día). | La fase 2 no contaba fallos y volver a pasar la contraseña reiniciaba todo. | `LoginThrottle` (5 fallos/email o 20/IP en 15 min, tabla `login_intentos`) y `AuthDomain::verify2fa` con clave por usuario (`2fa:<id>`) que un login correcto **no** borra. |
| ✅ **Borrar un tipo de usuario daba acceso total a sus usuarios**: `permissionsforuser` devolvía `null` (= todo) con `typeuserid` 0 o inexistente. | «Sin permisos = acceso total» por compatibilidad. | Sin tipo o con tipo inexistente → `[]` (nada); `typeuser_delete` se niega si quedan usuarios con ese tipo. Solo un tipo existente con `permissions` NULL da acceso total. |
| ✅ **La plantilla traía `public/assets/json/file-upload.php`**: subida de ficheros **sin autenticación** que se ejecutaba. | Material de demostración del tema copiado con los assets. | Borrado; `HtaccessTest` exige que en `public/` solo haya `index.php`, `app.php` y `router.php`. |
| ✅ **Cualquier `.php` real fuera de `public/` se ejecutaba** (código viejo en el docroot, subcarpetas con su propio `RewriteEngine`). | El `.htaccess` solo reescribía lo que no existía. | `<If>` que deniega todo script real salvo los dos front controllers (probado en Apache/PHP-FPM real: 403 en la raíz, en subcarpetas y con PATH_INFO). HTTPS forzado y `RemoveHandler .var` (las fuentes Inter `.var.woff2` daban 500). |
| ✅ **Las sesiones no se revocaban**: desactivar o borrar un usuario, o cambiar su contraseña, no cerraba sus sesiones; un cambio de tipo solo aplicaba al volver a entrar. | `App` confiaba en la copia del usuario guardada en sesión. | La sesión guarda una huella de la contraseña (`AuthDomain::fingerprint`, nunca el hash) y `App` relee el usuario en cada petición (`sessionuser`); cambiar la contraseña propia renueva la huella de esa sesión y cierra las demás. Más caducidad por inactividad (2 h), cookie `HttpOnly/SameSite/Secure` con `use_strict_mode` y cabeceras (`SecurityHeaders`). |
| ✅ **Cambio de contraseña y de 2FA sin reautenticación**: con una sesión robada se secuestraba la cuenta. | — | Exigen la contraseña actual; la nueva, mínimo 10 caracteres. |
| ✅ **`SettingsScreen` guardaba todo `$_POST`**: un POST con `deadline2fa` eximía a una cuenta del 2FA para siempre. | Asignación masiva. | `SettingsDomain::userfields` (lista blanca). |
| ✅ Logout por GET (cerrable desde otra web), token CSRF sin rotar al iniciar sesión y mensajes de excepción mostrados al usuario (500 con el texto interno; fase 2 del login). | — | Logout solo por POST con CSRF; `Csrf::rotate()` en `setUser`; el detalle solo a `error_log`. |
| ✅ **El email salía directo por SMTP desde cualquier entorno** (`send2FAEmail` → `Mailer::send`). | Sin interruptor de envío. | `MailDomain` con `MAIL_MODE` desactivado/sandbox/real; `real` solo con `APP_ENV=production` exacto; log sin datos personales (con el mensaje completo solo en `local`/`test`). `MailerUsageTest` impide llamar a `Mailer::send` desde otro sitio. |
| ✅ **`deploy.php` no subía nada con `FTP_PATHS=src,public`** (sin barra final). | `isdeployable` solo trataba como carpeta lo acabado en `/`. | Una entrada sin barra vale como fichero exacto o carpeta. Nuevo `deploy_ssh.php`. |
| ✅ QR del 2FA falso (SVG con el texto de la URI) y `maximebf/debugbar` abandonado. | — | QR real con `bacon/bacon-qr-code`; `php-debugbar/php-debugbar`. |

## Aprendizajes de operación (redGDPS 2026)

- **Staging nunca contra la BBDD de producción.** Y aunque sea temporal: `APP_ENV=staging` (no `production`) en su `.env`, o enviará emails reales con los datos reales. El modo de envío real exige las dos cosas a la vez.
- **El `.env` nunca antes que el `.htaccess` que lo protege**: un `.htaccess` antiguo lo serviría como texto plano.
- **FTPS de hosting compartido**: puede admitir **una sola sesión** (abrir más bloquea la IP unos minutos), colgar ficheros concretos (en Dinahosting, ~65 KB y otros) e ir a ~1 s por fichero; el listado NLST se trunca (~160 entradas) y las rutas absolutas pueden fallar donde las relativas funcionan. **Si hay SSH, desplegar por SSH** (`deploy_ssh.php`): 25 MB en un segundo.
- **PHP de consola ≠ PHP web**: en Dinahosting `php` era 7.0 y el 8.5 estaba en `/usr/local/bin/php85`. Comprobar la sintaxis con el de la versión real (`SSH_PHP`).
- **`vendor/` de producción con `composer install --no-dev --optimize-autoloader`**; se puede generar en local y subir en un `.tar.gz`.
- **URLs heredadas que devuelven la portada con 200 son «soft 404»** (Search Console las marca): responder 301 a la página equivalente, 410 si ya no existe.
- **Token de cron**: en la URL (`?token=`), comparado con `hash_equals`, ≥ 24 caracteres y 404 si falta o no coincide (sin revelar que la ruta existe). Un token corto se rechaza siempre.
- **Paso a producción en el mismo hosting**: ver `docs/paso-a-produccion.md`.

## Limitaciones conocidas (no corregidas a propósito)

- **CSP del panel en modo solo informe** (`Content-Security-Policy-Report-Only`, con `unsafe-inline`): el tema usa scripts y estilos en línea. Pasarla a aplicada exige nonces o sacar el código en línea; probarlo pantalla a pantalla.
- **Sin tabla de control de migraciones**: todo se reejecuta; exige disciplina de idempotencia.
- **Twig Environment por render**: se reconstruye en cada `rendertemplate()`. Aceptable en el panel; cachearlo para el CMS público.
- `ModelBase::validate` hace `echo` + `die` si falta un campo requerido (heredado; los Domain deben validar antes).
- `ModelBase::update` ignora valores `null`: para poner NULL hace falta un método específico en el Model.
- `Database` tiene ramas para Oracle/SQLite heredadas y sin uso.
- Dependencias sin uso en el esqueleto: `aws/aws-sdk-php`, `league/flysystem*` (`Core\Storage`, `Core\S3FILE`) y `mpdf/mpdf` (`Core\PDF`) suman ~70 MB de `vendor/`. En redGDPS se quitaron por no usarse; quitarlas aquí si el proyecto no los necesita.
- Nomenclatura mixta (minúsculas sin separador vs camelCase en 2FA/Request): pendiente de decidir; ver `CLAUDE.md`.
- `src/cache/views/` puede contener plantillas compiladas de las pruebas locales (ignoradas por git).
