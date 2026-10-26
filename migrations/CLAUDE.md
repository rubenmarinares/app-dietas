# migrations

- `php migrations/apply.php` aplica **todos** los `*.sql` en orden alfabético contra la BBDD de `.env`; `--test` contra la de `tests/.env.local`.
- **No hay tabla de control**: cada fichero se reejecuta en cada despliegue. Por eso toda migración debe ser idempotente:
  - `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (MariaDB/MySQL 8.0.29+), `INSERT IGNORE`.
  - `UPDATE` con valores fijos (fechas literales), nunca relativos (`CURDATE() + INTERVAL…` desplazaría el valor en cada deploy).
  - `apply.php` ignora los errores "Duplicate column", "Duplicate key name" y "already exists"; cualquier otro aborta.
- Sentencias separadas por `;` + salto de línea. No pongas `;` al final de una línea de comentario ni dentro de un `COMMENT '...'` seguido de salto.
- Nombre: `NNN_descripcion.sql` (3 dígitos, correlativo). `000_base_schema.sql` crea users, typesuser, users_logs, user_2fa, user_2fa_emails, settings y siembra el tipo "Administrador".
- Tras añadir columnas: actualizar `src/dbschema.php` (solo añadir) y pasar `tests/Integration/DbSchemaValidityTest.php`.
- Nunca sembrar usuarios con contraseña: `tools/create_admin.php`.
