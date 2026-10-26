<?php
/**
 * Constantes de la aplicación.
 *
 * Este fichero SÍ se versiona: no contiene ningún valor secreto. Todo valor
 * sensible o dependiente del entorno se lee del fichero .env de la raíz
 * (ver .env.example). Las variables reales del sistema tienen prioridad
 * sobre el .env (ver env.php).
 *
 * Los nombres de las constantes se mantienen por compatibilidad con el código
 * heredado (DNS, USERNAME, PASSWORD…): Database, Storage y Mailer los leen.
 */

require_once __DIR__ . '/env.php';
loadenv(dirname(__DIR__, 2) . '/.env');

// --- Aplicación ---
if (!defined('APP_NAME'))     define('APP_NAME', env('APP_NAME', 'redGDPS'));
if (!defined('APP_DEBUG'))    define('APP_DEBUG', envbool('APP_DEBUG', false));
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', env('APP_TIMEZONE', 'Europe/Madrid'));
// Días de gracia para configurar 2FA que se conceden a un usuario nuevo.
if (!defined('TWOFA_GRACE_DAYS')) define('TWOFA_GRACE_DAYS', envint('TWOFA_GRACE_DAYS', 7));

// --- Rutas ---
// LOCAL_PATH apunta a src/ (con barra final). No depende de DOCUMENT_ROOT para
// que funcione igual en Apache, en `php -S` y en CLI (migraciones, tests).
if (!defined('LOCAL_PATH'))         define('LOCAL_PATH', env('LOCAL_PATH', dirname(__DIR__) . '/'));
if (!defined('PUBLIC_PATH'))        define('PUBLIC_PATH', env('PUBLIC_PATH', '/'));
// APP_PATH puede venir predefinido por un front controller antes de cargar este fichero.
if (!defined('APP_PATH'))           define('APP_PATH', env('APP_PATH', '/app/'));
if (!defined('LOCAL_UPLOAD_PATH'))  define('LOCAL_UPLOAD_PATH', env('LOCAL_UPLOAD_PATH', dirname(__DIR__, 2) . '/uploads/'));
if (!defined('PUBLIC_UPLOAD_PATH')) define('PUBLIC_UPLOAD_PATH', env('PUBLIC_UPLOAD_PATH', '/uploads/'));

// --- Base de datos (MySQL/MariaDB) ---
if (!defined('DBNAME'))   define('DBNAME', env('DB_NAME', ''));
if (!defined('USERNAME')) define('USERNAME', env('DB_USER', ''));
if (!defined('PASSWORD')) define('PASSWORD', env('DB_PASS', ''));
if (!defined('DNS')) {
    $dbport = env('DB_PORT', '');
    define('DNS', 'mysql:dbname=' . DBNAME . ';host=' . env('DB_HOST', '127.0.0.1')
        . ($dbport !== null && $dbport !== '' ? ';port=' . $dbport : '') . ';charset=utf8mb4');
    unset($dbport);
}

// --- SMTP (Core\Mailer) ---
if (!defined('SMTP_HOST'))     define('SMTP_HOST', env('SMTP_HOST', 'localhost'));
if (!defined('SMTP_PORT'))     define('SMTP_PORT', envint('SMTP_PORT', 587));
if (!defined('SMTP_USER'))     define('SMTP_USER', env('SMTP_USER', ''));
if (!defined('SMTP_PASS'))     define('SMTP_PASS', env('SMTP_PASS', ''));
if (!defined('SMTP_FROM'))     define('SMTP_FROM', env('SMTP_FROM', 'no-reply@example.com'));
if (!defined('SMTP_FROMNAME')) define('SMTP_FROMNAME', env('SMTP_FROMNAME', APP_NAME));
// Dirección de contacto del sistema (avisos, remitente de soporte…). Nunca hardcodear.
if (!defined('SYSTEM_CONTACT_EMAIL')) define('SYSTEM_CONTACT_EMAIL', env('SYSTEM_CONTACT_EMAIL', ''));

// --- Almacenamiento opcional (Core\Storage / Core\S3FILE) ---
if (!defined('S3BUCKET'))   define('S3BUCKET', env('S3_BUCKET', ''));
if (!defined('S3USERNAME')) define('S3USERNAME', env('S3_KEY', ''));
if (!defined('S3PASSWORD')) define('S3PASSWORD', env('S3_SECRET', ''));
if (!defined('S3REGION'))   define('S3REGION', env('S3_REGION', 'eu-west-1'));

// FTP como backend de Storage. Distinto del FTP de despliegue (tools/deploy.php usa FTP_*).
if (!defined('FTPHOST'))     define('FTPHOST', env('STORAGE_FTP_HOST', ''));
if (!defined('FTPUSERNAME')) define('FTPUSERNAME', env('STORAGE_FTP_USER', ''));
if (!defined('FTPPASSWORD')) define('FTPPASSWORD', env('STORAGE_FTP_PASSWORD', ''));
if (!defined('FTPROOT'))     define('FTPROOT', env('STORAGE_FTP_ROOT', '/'));
if (!defined('FTPSSL'))      define('FTPSSL', envbool('STORAGE_FTP_SSL', false));
