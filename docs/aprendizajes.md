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

## Limitaciones conocidas (no corregidas a propósito)

- **QR de 2FA falso**: `AuthDomain::generateQRCode` devuelve un SVG con el texto de la URI `otpauth://`, no un QR escaneable. La pantalla muestra el secreto y la URI para alta manual. Para un QR real: añadir `endroid/qr-code` (lo usaba admin.redgdps en otra parte) o una librería JS.
- El secreto TOTP viaja en un campo oculto entre los pasos del asistente (protegido por CSRF y sesión). Alternativa: guardarlo en sesión.
- **Sin tabla de control de migraciones**: todo se reejecuta; exige disciplina de idempotencia.
- **Twig Environment por render**: se reconstruye en cada `rendertemplate()`. Aceptable en el panel; cachearlo para el CMS público.
- `ModelBase::validate` hace `echo` + `die` si falta un campo requerido (heredado; los Domain deben validar antes).
- `ModelBase::update` ignora valores `null`: para poner NULL hace falta un método específico en el Model.
- `Database` tiene ramas para Oracle/SQLite heredadas y sin uso.
- `maximebf/debugbar` está abandonado (sucesor `php-debugbar/php-debugbar`); solo se usa con `APP_DEBUG`.
- Nomenclatura mixta (minúsculas sin separador vs camelCase en 2FA/Request): pendiente de decidir; ver `CLAUDE.md`.
- `src/cache/views/` puede contener plantillas compiladas de las pruebas locales (ignoradas por git).
