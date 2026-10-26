<?php declare(strict_types=1);

// CLI: php migrations/apply.php [--test]
// Aplica TODOS los .sql de migrations/ en orden alfabético, en cada ejecución.
// No hay tabla de control: cada migración debe ser idempotente (ver migrations/CLAUDE.md).
//   --test  usa la BBDD de test de tests/.env.local (TEST_DBHOST, TEST_DBNAME…).

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';
require_once $root . '/src/Config/env.php';

$useTest = in_array('--test', $argv, true);
if ($useTest) {
    loadenv($root . '/tests/.env.local');
    $testdb = env('TEST_DBNAME');
    if ($testdb === null) {
        fwrite(STDERR, "Falta TEST_DBNAME (tests/.env.local o entorno). Copia tests/.env.local.example.\n");
        exit(1);
    }
    define('DBNAME',   $testdb);
    define('USERNAME', env('TEST_DBUSER', 'root'));
    define('PASSWORD', env('TEST_DBPASS', ''));
    $port = env('TEST_DBPORT', '');
    $portPart = $port !== '' ? ';port=' . $port : '';
    define('DNS', 'mysql:dbname=' . DBNAME . ';host=' . env('TEST_DBHOST', '127.0.0.1') . $portPart . ';charset=utf8mb4');
} else {
    require $root . '/src/Config/constants.php';
}

$pdo = new PDO(DNS, USERNAME, PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$files = glob(__DIR__ . '/*.sql');
sort($files);
foreach ($files as $file) {
    echo "Applying " . basename($file) . "... ";
    $sql = file_get_contents($file);
    $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
    foreach ($statements as $stmt) {
        // Strip leading SQL line comments so statements preceded by -- headers still run.
        $stmt = preg_replace('/^(?:\s*--[^\r\n]*(?:\r\n|\n|\r))+/', '', $stmt);
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key name')
                || str_contains($msg, 'already exists')) {
                continue;
            }
            echo "FAILED at: " . substr($stmt, 0, 120) . "\n$msg\n";
            exit(1);
        }
    }
    echo "OK\n";
}
echo "All migrations applied.\n";
