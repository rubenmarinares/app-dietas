# CLAUDE.md

Guía operativa para trabajar en este esqueleto con Claude Code. Detalle por capa:
[src/Core](src/Core/CLAUDE.md) · [src/Domain](src/Domain/CLAUDE.md) · [src/Domain/Models](src/Domain/Models/CLAUDE.md) · [src/Screens](src/Screens/CLAUDE.md) · [src/Screens/Views](src/Screens/Views/CLAUDE.md) · [migrations](migrations/CLAUDE.md) · [tests](tests/CLAUDE.md) · [tools](tools/CLAUDE.md). El porqué de cada decisión: [docs/decisiones.md](docs/decisiones.md). Errores ya cometidos: [docs/aprendizajes.md](docs/aprendizajes.md).

## Reglas duras

1. **Capas: `Screen → Domain → Domain\Models`.** Las Screens nunca usan `Domain\Models`. El SQL vive en Models (Domain usa métodos de Model). `tests/Unit/ArchitectureTest.php` lo vigila.
2. **Toda columna nueva va a la migración Y a `src/dbschema.php`.** Si no está en dbschema, `ModelBase` no la ve (insert/update/search la ignoran en silencio).
3. **dbschema "solo añadir".** Nunca borrar ni pisar propiedades ya fijadas (`primary`, `maincol`, `ordercol`, `default`, `type => 'json'`). `tools/regen_dbschema.php` ya respeta esto.
4. **Migraciones idempotentes.** `apply.php` reejecuta TODOS los `.sql` en cada despliegue: `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`, fechas fijas (nunca `CURDATE()` en un UPDATE).
5. **Validar antes de implementar.** Comprobar en `dbschema.php` nombres exactos de tablas/columnas y con `grep "function nombre" src/...` que el método existe. No inventar métodos.
6. **Secretos solo en `.env`.** `constants.php` se versiona y solo lee `env()`. Nunca escribir credenciales, emails personales ni claves en código.
7. **Todo POST valida CSRF** (`Core\Csrf::validate($_POST['csrf_token'])`; las plantillas tienen `csrf_token` global).
8. **GET que muta estado ⇒ `'perm' => 'write'`** en la ruta y alta en `tests/Unit/RoutesPermTest.php`.
9. **Continuidad de diseño en plantillas.** No simplificar cards/clases Bootstrap existentes; mirar `git log -p` del fichero antes de reescribirlo.
10. **Idioma:** UI, comentarios y docs en español; identificadores en inglés. Clases `UpperCamelCase`. Métodos/variables: ver "Nomenclatura".

## Arquitectura

```
/app/xxx ─.htaccess─▶ public/app.php (session_start)
           └▶ src/Bootstrap.php: autoload, functions.php, constants.php(.env), timezone, debugbar(APP_DEBUG),
                                 Database::getInstance(), dbschema.php ($dbschema global), Menu.php ($menu)
              └▶ new App($db, RoutesApp.php)   ← el constructor ejecuta TODA la petición
                   1. FastRoute dispatch (ruta sin APP_PATH)
                   2. Gate auth: si la Screen no está en App::PUBLICSCREENS y no hay sesión → /login
                   3. Gate permisos: módulo = nombre de la Screen ("SettingsScreen" → "settings");
                      nivel = GET/HEAD→read, resto→write (o 'perm' de la ruta) vs typesuser.permissions
                   4. new Screen($app)->metodo(...$vars)
                        └▶ Domain::metodo($app, …) └▶ Model::get/search/insert/update/upsert($app->db, …)
                        └▶ $this->rendertemplate('vista.html', $data)  (Twig, layout.html)
```

No hay middleware ni contenedor de dependencias: lo transversal está en `App::__construct`.

## Comandos

| Tarea | Comando |
|---|---|
| Dependencias | `composer install` |
| Servidor local | `php -S localhost:8000 public/router.php` → `/app/` |
| Migraciones | `php migrations/apply.php` (`--test` para la BBDD de test) |
| Crear admin | `php tools/create_admin.php email "Nombre" "Apellidos"` |
| Tests | `vendor/bin/phpunit tests/Unit` · `vendor/bin/phpunit` (con BBDD) · `--filter nombreTest` |
| Lint PHP | `php -l fichero.php` (no hay linter global) |
| Lint Twig | `php tools/twig_lint.php` |
| Sincronizar dbschema | `php tools/regen_dbschema.php --dry-run` y luego sin `--dry-run` |
| Desplegar | `php tools/deploy.php --dry-run` (FTP; ver tools/CLAUDE.md) |

## Recetas

### Nueva tabla + Model
1. `migrations/NNN_descripcion.sql` con `CREATE TABLE IF NOT EXISTS …` (InnoDB, utf8mb4).
2. Entrada en `src/dbschema.php` (tipos, `primary`, `autoincrement`, `required`, `length`, `maincol`, `ordercol`, `json`).
3. `src/Domain/Models/Xxx.php` extendiendo `Core\ModelBase` con `static public $tablename='xxx';` (+ `$joins`, `$combos`, `$defaultorder` si hace falta).
4. `php migrations/apply.php --test` y `vendor/bin/phpunit tests/Integration/DbSchemaValidityTest.php`.

### Nueva pantalla del panel
1. `src/Domain/XxxDomain.php`: métodos `static public function list($app, $params)` etc. que llaman al Model.
2. `src/Screens/XxxScreen.php` extendiendo `Core\ScreenBase`; valida CSRF en POST, llama a Domain y hace `$this->rendertemplate('xxx.html', [...])`.
3. Rutas en `src/RoutesApp.php`: `[['GET'], 'xxx', ['App\Screens\XxxScreen', 'index']]`, `[['GET','POST'], 'xxx/{id:\d+}', ['App\Screens\XxxScreen', 'edit']]`.
4. Plantilla `src/Screens/Views/xxx.html` con `{% extends "layout.html" %}` y `{% set imenu1=N %}`.
5. Entrada en `src/Menu.php` con `'module' => 'xxx'` (clave N).
6. Permisos: el módulo es `xxx`; da acceso en Configuración → Tipos de usuario (`{"xxx":"write"}` o `{"*":"read"}`).

### Pantalla pública (CMS)
Añadir el FQCN a `App::PUBLICSCREENS`. No pasa por el gate de permisos: no expongas escrituras sin tu propia validación. Para la web pública conviene un front controller propio en `public/index.php` con su fichero de rutas (ver docs/decisiones.md).

## Nomenclatura (real, verificada en el código)

- Clases: `UpperCamelCase` (`AuthDomain`, `SettingsScreen`).
- El código heredado mezcla dos estilos: **minúsculas sin separador** (`modulefromclass`, `permissionsforuser`, `requiredlevelformethod`, `setparam`, `$requiredlevel`, `$typeuserid`) — mayoritario en Core/Domain/Models y en las variables de plantilla — y **camelCase** (`generateTOTPSecret`, `hasConfigured2FA`, `isInGracePeriod`, `getRealMethod`, `$toEmail`) en el módulo 2FA y en Request.
- **Decisión pendiente** (el CLAUDE.md original pedía minúsculas; una preferencia posterior del usuario pide camelCase). Hasta que se fije: sigue el estilo del fichero que tocas; en ficheros nuevos, minúsculas sin separador (coherente con dbschema, rutas, ModelBase y Twig). No renombrar lo existente sin un cambio dedicado.
- Columnas de BBDD: minúsculas; nuevas sin guion bajo (`deadline2fa`), aunque las tablas 2FA heredadas usan `snake_case` (`primary_method`).
