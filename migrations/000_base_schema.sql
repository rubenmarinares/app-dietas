-- Esquema base del esqueleto: usuarios, tipos de usuario (permisos), log de accesos,
-- 2FA y ajustes clave/valor. Idempotente: apply.php reejecuta TODOS los .sql en cada
-- despliegue (no hay tabla de control), así que todo es CREATE ... IF NOT EXISTS /
-- INSERT IGNORE.
--
-- En el proyecto de origen estas tablas nunca tuvieron CREATE TABLE en migrations/ (existían
-- antes del sistema de migraciones). Aquí se derivan de src/dbschema.php y se pliegan
-- las migraciones de origen 004 (typesuser.permissions), 005 (tablas 2FA y
-- users.deadline2fa), 006 (totp_secret VARCHAR(128)), 007 y 015 (ampliaciones del
-- plazo de gracia con fechas fijas: no aplican a una instalación nueva).
--
-- Cualquier columna que se añada aquí o en una migración posterior DEBE añadirse
-- también a src/dbschema.php (regla "solo añadir").

CREATE TABLE IF NOT EXISTS typesuser (
    idtypeuser INT NOT NULL AUTO_INCREMENT,
    typeuser VARCHAR(250) NOT NULL,
    permissions JSON NULL COMMENT 'Permisos por módulo: {"modulo"|"*": "none"|"read"|"write"}. NULL = acceso total.',
    PRIMARY KEY (idtypeuser)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    iduser INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(250) NOT NULL,
    surname VARCHAR(250) NOT NULL DEFAULT '',
    email VARCHAR(250) NOT NULL,
    password VARCHAR(250) NULL COMMENT 'password_hash(). Hashes md5 heredados se migran en el primer login.',
    status INT NOT NULL DEFAULT 0 COMMENT '1 activo, 0 inactivo',
    created INT NOT NULL DEFAULT 0 COMMENT 'yyyymmdd',
    updated INT NOT NULL DEFAULT 0 COMMENT 'yyyymmdd',
    typeuserid INT NOT NULL DEFAULT 0,
    deadline2fa INT NULL COMMENT 'Plazo de gracia para configurar 2FA (yyyymmdd). NULL = sin plazo (2FA configurado o bloqueado).',
    PRIMARY KEY (iduser),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_typeuserid (typeuserid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users_logs (
    idlog INT NOT NULL AUTO_INCREMENT,
    userid INT NULL,
    action VARCHAR(45) NULL COMMENT 'login | denied | …',
    stamp INT NULL COMMENT 'yyyymmdd',
    PRIMARY KEY (idlog),
    KEY idx_users_logs_userid (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_2fa (
    userid INT NOT NULL,
    primary_method ENUM('totp', 'email') NOT NULL,
    backup_method ENUM('totp', 'email') NOT NULL,
    totp_secret VARCHAR(128) NULL COMMENT 'Secreto TOTP en Base32 (OTPHP genera ~103 caracteres: VARCHAR(32) truncaba).',
    backup_email VARCHAR(255) NULL COMMENT 'Email para el método de respaldo o principal.',
    configured_at INT NOT NULL COMMENT 'yyyymmdd en que se configuró',
    PRIMARY KEY (userid),
    CONSTRAINT fk_user_2fa_user FOREIGN KEY (userid) REFERENCES users(iduser) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_2fa_emails (
    userid INT NOT NULL,
    code VARCHAR(10) NOT NULL,
    created_at INT NOT NULL COMMENT 'Unix timestamp',
    attempts INT DEFAULT 0 COMMENT 'Intentos fallidos. Bloqueo tras 3.',
    PRIMARY KEY (userid),
    CONSTRAINT fk_user_2fa_emails_user FOREIGN KEY (userid) REFERENCES users(iduser) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    `key` VARCHAR(50) NOT NULL,
    props JSON NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Semilla: tipo Administrador con escritura en todos los módulos. Formato que espera
-- PermissionDomain::levelfor(): {"<modulo>": "none|read|write", "*": comodín}.
-- (NULL también daría acceso total; se usa {"*":"write"} para que sea explícito.)
-- NO se siembra ningún usuario con contraseña: usar `php tools/create_admin.php`.
INSERT IGNORE INTO typesuser (idtypeuser, typeuser, permissions)
VALUES (1, 'Administrador', '{"*": "write"}');
