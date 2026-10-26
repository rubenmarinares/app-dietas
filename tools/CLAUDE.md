# tools (CLI)

| Script | Uso | Notas |
|---|---|---|
| `create_admin.php` | `php tools/create_admin.php email "Nombre" ["Apellidos"]` | Pide la contraseña por consola (mín. 10). Tipo 1 "Administrador". Da `TWOFA_GRACE_DAYS` días para configurar 2FA. |
| `deploy.php` | `php tools/deploy.php --dry-run` y luego sin `--dry-run` | Sube por FTP lo cambiado entre el último commit desplegado (`<FTP_ROOT>/.deployed_commit`) y `HEAD`, limitado a `FTP_PATHS`; nunca sube `.md`. Avisa si cambian `migrations/` o `composer.lock` (hay que aplicarlos a mano). Variables `FTP_HOST/PORT/USER/PASSWORD/ROOT/SSL/PATHS` en `.env` (es `FTP_PASSWORD`, no `FTP_PASS`). La primera vez: `--since=<sha>`. Otras: `--delete`, `--yes`, `--detect`, `--paths=a,b`. Lógica pura en `Deploy/DeployPlan.php` y `Deploy/DeployDetect.php` (con tests). |
| `regen_dbschema.php` | `php tools/regen_dbschema.php [--test] [--dry-run]` | Sincroniza `src/dbschema.php` con la BBDD **solo añadiendo** tablas, columnas y propiedades que falten. Overrides (maincol/json/primary/ignore) en `regen_dbschema.config.php` (sin secretos). |
| `twig_lint.php` | `php tools/twig_lint.php` | Parsea todas las plantillas. Mantener su lista de funciones/filtros igual que `ScreenBase`. |

Ninguno debe ser accesible por web: `.htaccess` devuelve 404 para `/tools`.
