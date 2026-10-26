-- Límite de intentos de login y del segundo factor: solo se registran los fallos (email normalizado o clave 2fa:<iduser>, IP y time()).
-- Idempotente. Las filas con más de 1 día se purgan desde la aplicación.

CREATE TABLE IF NOT EXISTS login_intentos (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(250) NOT NULL DEFAULT '',
    ip VARCHAR(45) NOT NULL DEFAULT '',
    stamp INT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_login_intentos_email (email, stamp),
    KEY idx_login_intentos_ip (ip, stamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
