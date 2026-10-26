# Paso a producción (plantilla)

Guía genérica para publicar un proyecto hecho con este esqueleto **en el mismo hosting donde vive la web anterior**, con la mínima caída de servicio y vuelta atrás en minutos. Sale del corte de redGDPS (octubre de 2026). Copiarla a `docs/` del proyecto y concretarla (carpetas, BBDD, crons, delta de datos).

## Principios

- **Preparar todo fuera de la web y cambiar con renombrados.** Nada se borra durante el corte: lo viejo se aparta a una carpeta hermana del docroot y se recupera renombrando. La caída dura lo que tardan unos `mv` (segundos), no lo que tarda subir el código.
- **Por SSH, no por FTP**, si el hosting lo permite (ver `docs/aprendizajes.md`, «Aprendizajes de operación»).
- **Cada paso con su comprobación** y un registro de lo hecho (para deshacerlo en orden inverso).
- **Nunca un email real fuera de producción**: `APP_ENV=production` solo en el `.env` de producción; staging con `APP_ENV=staging` y, mejor, con su propia BBDD.

## Antes (sin prisa, la web vieja sigue funcionando)

1. **Inventario** del docroot actual (`ls -A`): qué se queda (certificados `.well-known/`, subidas que el CMS sigue sirviendo, ficheros de verificación de buscadores, `robots.txt`), qué se aparta y qué se sustituye. Decidir las carpetas dudosas antes del día del corte.
2. **Versión de PHP** del hosting (web y consola: pueden ser distintas) y extensiones (`pdo_mysql`, `mbstring`, `openssl`).
3. **`.env` de producción** completo (lo escribe una persona, nunca se versiona): `APP_ENV=production`, `APP_DEBUG=0`, `APP_URL`, `DB_*` (usuario MySQL propio de producción), `SMTP_*`, `MAIL_MODE=real`, tokens (≥ 24 caracteres).
4. **Copias de seguridad** fuera del servidor: dump de la BBDD nueva y de la vieja, y descarga del docroot actual.
5. **Copia limpia** de las carpetas estáticas heredadas que se conservan: **sin ningún script** (`.php*`, `.phtml`, `.phar`, `.inc`, `.cgi`…). Aun así, el `.htaccess` del esqueleto deniega cualquier script real fuera de los front controllers.
6. **`vendor/`** con `composer install --no-dev --optimize-autoloader`.
7. **Ensayos en seco**: `deploy_ssh.php --dry-run`, tests, lint de plantillas, informe de diferencias de datos con la web vieja (delta).

## El día del corte

1. **Congelar la web vieja**: un bloque al principio de su `.htaccess` que responda 503 a todo POST (guardar copia del original). Avisar a los editores. Comprobar: GET 200, POST 503.
2. **Quitar los cron viejos** (o quedarán apuntando a URLs que pasan al CMS).
3. **Traer el delta** de datos (lo creado en la web vieja desde la migración): solo inserciones, en una transacción, con ensayo previo. Lo legalmente obligatorio (bajas de boletines) no se puede perder.
4. **Migraciones** contra la BBDD de producción (`php migrations/apply.php`).
5. **Preparar `~/www_nuevo/`** fuera de la web: código del commit, `vendor/`, carpetas estáticas limpias, `.deployed_commit` y el `.env` (lo coloca una persona). Comprobar con el PHP de consola de la versión real: `php -l` de todo y renderizar un par de páginas por CLI contra la BBDD real.
6. **Cambio** (script con registro de cada `mv`, primero en ensayo): `www/<todo lo que no se queda>` → `~/www_2016/`; `~/www_nuevo/*` → `www/`. Vuelta atrás: los mismos `mv` al revés.
7. **Humo**: portada, contenidos, panel con login y 2FA, URLs heredadas (301/410, no portada con 200), ficheros sensibles (403/404), cabeceras de seguridad, `http://` → `https://`, cron sin token → 404.
8. **Cron nuevo** (cada 1–2 min si hay envíos por lotes) y **envío real**: una prueba a un buzón propio y revisar SPF/DKIM.
9. **Desactivar staging** si escribía en la BBDD de producción.

## Después

- **Vigilar 48 h**: log de errores de Apache y 404 que lleguen desde fuera (decidir qué se recupera de `www_2016/`).
- **Antes de borrar `www_2016/`**: comprobar que ningún contenido del CMS enlaza a algo que solo esté ahí (en redGDPS faltaban las imágenes de un módulo y una carpeta de subidas antigua). Al borrarla se pierde la vuelta atrás rápida.
- **Rotar credenciales** que hayan pasado por ficheros temporales o chats, y revocar claves SSH de uso puntual.
