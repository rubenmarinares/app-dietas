# skeleton

Esqueleto PHP 8.1 extraído de un panel de administración en producción: la capa transversal (arranque, rutas, login + 2FA, usuarios y permisos, layout Twig, ModelBase/dbschema, correo, almacenamiento, herramientas y tests) **sin ningún dominio de negocio**. Sirve de base para nuevos paneles y CMS.

Stack: FastRoute + Twig 3 + PDO (wrapper propio) + `ModelBase` guiado por `src/dbschema.php`. Sin framework. PSR-4 `App\` → `src/`.

## Puesta en marcha

```bash
composer install
cp .env.example .env                      # rellenar DB_*, SMTP_*, APP_NAME…
php migrations/apply.php                  # crea las 6 tablas base + tipo "Administrador"
php tools/create_admin.php tu@email.es "Nombre" "Apellidos"   # pide la contraseña
php -S localhost:8000 public/router.php   # desarrollo → http://localhost:8000/app/
```

Con Apache: el `DocumentRoot` es la raíz del proyecto; `.htaccess` reescribe `/app/*` a `public/app.php`, el resto a `public/` y bloquea `.env`, claves y carpetas internas.

El admin creado tiene `TWOFA_GRACE_DAYS` días para configurar 2FA en **Mi perfil**; después el login exige el segundo factor.

## Tests

```bash
vendor/bin/phpunit tests/Unit             # sin BBDD
cp tests/.env.local.example tests/.env.local   # BBDD de test AISLADA
php migrations/apply.php --test
vendor/bin/phpunit                        # unit + integración (si no hay BBDD, se saltan)
php tools/twig_lint.php                   # sintaxis de todas las plantillas
```

## Mapa de carpetas

| Ruta | Qué hay |
|---|---|
| `public/` | Front controllers (`app.php`, `index.php`), `router.php` para `php -S`, `assets/` (tema Bootstrap 5) |
| `src/Bootstrap.php` | Arranque común: autoload → helpers → constantes (.env) → BD → dbschema → menú → `App` |
| `src/Config/` | `env.php` (lector de .env) y `constants.php` (sin secretos, versionado) |
| `src/Core/` | Micro-framework: `App`, `ScreenBase`, `Database`, `ModelBase`, `Session`, `Request`, `Csrf`, `Mailer`, `Storage`, `PDF`… |
| `src/Domain/` | Lógica: `AuthDomain`, `PermissionDomain`, `UserDomain`, `SettingsDomain`, `SettingDomain` |
| `src/Domain/Models/` | Un Model por tabla (`User`, `TypeUser`, `UserLog`, `TwoFactor*`, `Setting`) |
| `src/Screens/` | Controladores HTML + `Views/` (Twig) |
| `src/RoutesApp.php`, `src/Menu.php` | Rutas del panel y menú lateral |
| `src/dbschema.php` | Esquema de BBDD: fuente de verdad de ModelBase |
| `migrations/` | SQL idempotentes + `apply.php` |
| `tools/` | `create_admin.php`, `deploy.php` (FTP), `regen_dbschema.php`, `twig_lint.php` |
| `tests/` | PHPUnit 10: `Unit/` (sin BBDD) e `Integration/` (BBDD real) |
| `docs/` | `decisiones.md` (por qué de cada elección) y `aprendizajes.md` (errores heredados y cómo evitarlos) |

Antes de programar, lee `CLAUDE.md`.
