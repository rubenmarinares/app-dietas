# Decisiones de arquitectura

Formato: **Decisión** · **Por qué** · **Alternativa descartada**. Heredadas de admin.redgdps salvo que se indique "(esqueleto)".

## Pila

| # | Decisión | Por qué | Alternativa descartada |
|---|---|---|---|
| 1 | **Sin framework**: micro-stack propio (FastRoute + Twig + PDO). | El equipo conoce el código entero; despliegue por FTP en hosting compartido sin CLI; cero magia que depurar. | Laravel/Symfony: curva, actualizaciones mayores y requisitos de hosting que no compensan para un panel interno + CMS pequeño. |
| 2 | **FastRoute** para el enrutado, rutas como arrays planos en `RoutesApp.php`. | Rápido, sin dependencias, rutas legibles y testeables (`RoutesPermTest`). | Router propio con regex (frágil) o anotaciones (ocultan el mapa de rutas). |
| 3 | **Twig 3** para vistas. | Autoescape por defecto (XSS), herencia de layouts, filtros propios. | PHP plano en vistas: sin autoescape, mezcla lógica y HTML. |
| 4 | **PDO con wrapper `db`** (`run`, `runone`, `insert`, `update`). | Consultas preparadas siempre; atajos cómodos. | ORM (Doctrine/Eloquent): peso y otra capa de abstracción. |
| 5 | **Tema Bootstrap 5 (Able Pro) + jQuery** con plugins en `head.html`. | Diseño consistente sin build de front. | SPA/React: duplicaría la lógica y exige pipeline de build. |

## Datos

| # | Decisión | Por qué | Alternativa descartada |
|---|---|---|---|
| 6 | **`src/dbschema.php` como fuente de verdad** del esquema para el código. | `ModelBase` necesita tipos, primarias, maincol, ordercol y json sin consultar `INFORMATION_SCHEMA` en cada petición. | Introspección en caliente (lenta) o atributos por Model (duplica información y se desincroniza igual). |
| 7 | **`ModelBase` genérico** (get/search/insert/update/upsert/delete/combos) y un Model por tabla. | CRUD sin SQL repetido; filtros parametrizados y validados contra el esquema. | SQL a mano en cada Domain: repetitivo y propenso a inyección. |
| 8 | **Dinero como entero × 10000** (4 decimales fijos). | Sin errores de coma flotante; sumas exactas. Filtros `monedaatexto`/`monedaaentero`. | `FLOAT` (errores de redondeo) o `DECIMAL` (válido, pero el código heredado ya usa enteros). |
| 9 | **Fechas `int yyyymmdd`** en tablas heredadas (`created`, `updated`, `deadline2fa`, `users_logs.stamp`); `DATETIME` en tablas nuevas donde importe la hora. | Comparables como enteros, independientes de zona horaria, fáciles de filtrar con BETWEEN. | Todo `DATETIME` (más correcto, pero rompe compatibilidad con los datos migrados). Para tablas nuevas del CMS: preferir `DATETIME` salvo que se compare con fechas legacy. |
| 10 | **Migraciones `.sql` reaplicadas enteras y sin tabla de control.** | Sencillo de desplegar a mano en hosting compartido; ver el SQL exacto. | Tabla `migrations` con versión (más robusto; candidata si el número de migraciones crece). Exige idempotencia estricta (ver `migrations/CLAUDE.md`). |

## Seguridad y acceso

| # | Decisión | Por qué | Alternativa descartada |
|---|---|---|---|
| 11 | **Permisos JSON en `typesuser.permissions`** (`{"modulo"|"*": none|read|write}`), módulo derivado del nombre de la Screen y nivel del verbo HTTP. | Un solo punto de control (gate en `App`) sin tocar cada Screen; editable desde Configuración; se lee en cada petición (bajar permisos aplica al instante). | Tablas roles/permisos normalizadas (más complejo para 3-4 perfiles) o comprobaciones dispersas en cada método. |
| 12 | **`NULL` = acceso total; JSON no-objeto = nada (fail closed).** | Retrocompatibilidad con tipos antiguos y seguridad ante datos corruptos. | Interpretar basura como acceso total. |
| 13 | **2FA obligatorio (TOTP o código por email) con plazo de gracia** (`users.deadline2fa`). | Panel con datos sensibles; el plazo permite desplegar sin bloquear a nadie. | 2FA opcional (nadie lo activa) o SMS (coste y SIM swapping). |
| 14 | **(esqueleto) `password_hash` con migración transparente desde md5**: si el hash guardado es md5 y coincide, se reescribe en ese login. | md5 sin sal es reversible con tablas; migrar sin forzar reseteo masivo. | Resetear todas las contraseñas (fricción) o mantener md5. |
| 15 | **(esqueleto) Login en dos fases sin abrir sesión antes del 2FA** (`verifycredentials` → `completelogin`). | Antes se abría sesión, se destruía y se intentaba guardar el estado pendiente en una sesión ya destruida. | Abrir y cerrar sesión (frágil, ver aprendizajes). |
| 16 | **CSRF de sesión único (`Core\Csrf`)** en todos los POST. | Un mecanismo, token estable por sesión (funciona con varias pestañas y sidepanels AJAX). | Tokens por formulario de un solo uso (rompen con AJAX y pestañas). |
| 17 | **`App::PUBLICSCREENS` como lista blanca** de pantallas sin login. | Por defecto todo es privado: olvidar algo cierra, no abre. | Lista negra de pantallas privadas. |
| 18 | **(esqueleto) Secretos solo en `.env`**; `constants.php` versionado leyendo `env()`. | En el origen `constants.php` tenía credenciales y no podía versionarse. | Constantes con valores en fichero ignorado (se pierden y no se revisan). |

## Estructura y operación

| # | Decisión | Por qué | Alternativa descartada |
|---|---|---|---|
| 19 | **Capas `Screen → Domain → Model`**, Domain estático que recibe `$app`/`$db`. | Lógica testeable sin HTTP; SQL localizado; tests de arquitectura lo vigilan. | Lógica en Screens (no testeable) o servicios con contenedor DI (más ceremonia). |
| 20 | **Sin middleware**: auth y permisos en `App::__construct`. | Flujo lineal y fácil de leer. | Pipeline PSR-15: más flexible, pero innecesario hoy. Si el CMS público necesita caché/headers por ruta, valorar añadir un hook previo al dispatch. |
| 21 | **Despliegue por FTP con `tools/deploy.php`** (diff git entre el commit desplegado y HEAD). | El hosting solo ofrece FTP; subir solo lo cambiado, leyendo del commit (no de la copia de trabajo). | Subida manual (errores) o CI/CD con SSH (no disponible). `vendor/` y migraciones se gestionan aparte. |
| 22 | **Tests de integración contra MySQL real** (sin mocks de PDO). | Los fallos reales eran de esquema/SQL; un mock no los ve. | SQLite en memoria (dialecto distinto) o mocks. |
| 23 | **(esqueleto) DebugBar solo con `APP_DEBUG=1`**. | En el origen se pintaba siempre, también en producción. | Siempre activa. |
| 24 | **(esqueleto) Front controller público separado del panel** (hoy `public/index.php` redirige a `/app/`). | El CMS público tendrá otras rutas, sin sesión de admin ni permisos; mezclarlo con `RoutesApp.php` complica el gate. | Reutilizar `RoutesApp.php` con muchas `PUBLICSCREENS`. |
