# tools (CLI)

| Script | Uso | Notas |
|---|---|---|
| `create_admin.php` | `php tools/create_admin.php email "Nombre" ["Apellidos"]` | Pide la contraseña por consola (mín. 10). Tipo 1 "Administrador". Da `TWOFA_GRACE_DAYS` días para configurar 2FA. |
| `deploy.php` | `php tools/deploy.php --dry-run` y luego sin `--dry-run` | Sube por FTP (si hay SSH, mejor `deploy_ssh.php`; ojo: algunos hostings limitan a una sesión FTPS y bloquean la IP si se abren más, y el listado NLST se trunca, así que `--detect` no es fiable) lo cambiado entre el último commit desplegado (`<FTP_ROOT>/.deployed_commit`) y `HEAD`, limitado a `FTP_PATHS`; nunca sube `.md`. Avisa si cambian `migrations/` o `composer.lock` (hay que aplicarlos a mano). Variables `FTP_HOST/PORT/USER/PASSWORD/ROOT/SSL/PATHS` en `.env` (es `FTP_PASSWORD`, no `FTP_PASS`). La primera vez: `--since=<sha>`. Otras: `--delete`, `--yes`, `--detect`, `--paths=a,b`. Lógica pura en `Deploy/DeployPlan.php` y `Deploy/DeployDetect.php` (con tests). |
| `deploy_ssh.php` | `php tools/deploy_ssh.php --dry-run` y luego sin `--dry-run` | **Recomendado si el hosting da SSH.** Mismo plan y mismo marcador que `deploy.php`, pero empaqueta los cambios del commit en un `.tar.gz` (PharData, contenido exacto), lo sube con `scp` y lo descomprime con `ssh`: segundos, frente a FTPS lento o limitado a una sesión. `--delete` borra los eliminados en git; con `SSH_PHP` (p. ej. `/usr/local/bin/php85`) comprueba la sintaxis en el servidor y, si falla, no actualiza el marcador. Variables `SSH_HOST/USER/PORT/KEY/ROOT/PHP` y `DEPLOY_PATHS` (o `FTP_PATHS`). Solo clave, nunca contraseña (`BatchMode=yes`). Comandos remotos en `Deploy/DeploySsh.php` (puro, con tests). |
| `regen_dbschema.php` | `php tools/regen_dbschema.php [--test] [--dry-run]` | Sincroniza `src/dbschema.php` con la BBDD **solo añadiendo** tablas, columnas y propiedades que falten. Overrides (maincol/json/primary/ignore) en `regen_dbschema.config.php` (sin secretos). |
| `twig_lint.php` | `php tools/twig_lint.php` | Parsea todas las plantillas. Mantener su lista de funciones/filtros igual que `ScreenBase`. |

Ninguno debe ser accesible por web: `.htaccess` devuelve 404 para `/tools`.
