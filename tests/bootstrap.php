<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/functions.php';
require_once __DIR__ . '/../src/Config/env.php';

// Overrides locales de test (tests/.env.local, no versionado; ver tests/.env.local.example).
loadenv(__DIR__ . '/.env.local');

// Constantes de test. Se definen ANTES de constants.php para usar una BBDD aislada.
if (!defined('DBNAME'))   define('DBNAME',   env('TEST_DBNAME', 'skeleton_test'));
if (!defined('USERNAME')) define('USERNAME', env('TEST_DBUSER', 'root'));
if (!defined('PASSWORD')) define('PASSWORD', env('TEST_DBPASS', ''));
if (!defined('DNS')) {
    $testPort = env('TEST_DBPORT', '');
    define('DNS', 'mysql:dbname=' . DBNAME . ';host=' . env('TEST_DBHOST', '127.0.0.1')
        . ($testPort !== null && $testPort !== '' ? ';port=' . $testPort : '') . ';charset=utf8mb4');
    unset($testPort);
}
if (!defined('APP_NAME'))      define('APP_NAME', 'Skeleton Test');
if (!defined('APP_DEBUG'))     define('APP_DEBUG', false);
if (!defined('SMTP_HOST'))     define('SMTP_HOST', 'localhost');
if (!defined('SMTP_PORT'))     define('SMTP_PORT', 1025);
if (!defined('SMTP_USER'))     define('SMTP_USER', '');
if (!defined('SMTP_PASS'))     define('SMTP_PASS', '');
if (!defined('SMTP_FROM'))     define('SMTP_FROM', 'no-reply@test.local');
if (!defined('SMTP_FROMNAME')) define('SMTP_FROMNAME', 'Skeleton Test');

// El resto (rutas, storage…) con los valores por defecto de constants.php.
// No carga el .env de la raíz para valores ya definidos arriba: define() con guardas.
require __DIR__ . '/../src/Config/constants.php';
date_default_timezone_set(APP_TIMEZONE);

// $GLOBALS["dbschema"]: ModelBase lo necesita (primarias, tipos, json).
require __DIR__ . '/../src/dbschema.php';
$GLOBALS['dbschema'] = $dbschema;

/**
 * Conexión de test bajo demanda. Los tests unitarios NO necesitan BBDD; los de
 * integración llaman a testdbavailable() y se marcan como skipped si no hay BBDD.
 * Lock global: evita que dos ejecuciones concurrentes se pisen la BBDD compartida.
 */
function testdbavailable(): bool
{
    static $available = null;
    if ($available !== null) return $available;
    try {
        $GLOBALS['__testlock'] = new \PDO(DNS, USERNAME, PASSWORD, [\PDO::ATTR_TIMEOUT => 3]);
        $res = $GLOBALS['__testlock']->query("SELECT GET_LOCK('skeleton_tests', 600)")->fetchColumn();
        if ((int) $res !== 1) {
            fwrite(STDERR, "Otra ejecución de tests está usando la BBDD de test. Reintenta cuando termine.\n");
            exit(1);
        }
        $available = true;
    } catch (\Throwable $e) {
        $available = false;
    }
    return $available;
}
