<?php declare(strict_types=1);

// Uso:
//   php tools/regen_dbschema.php            # sincroniza src/dbschema.php con la BBDD de .env
//   php tools/regen_dbschema.php --test     # contra la BBDD de test (tests/.env.local)
//   php tools/regen_dbschema.php --dry-run  # muestra el resultado sin escribir
//
// REGLA "SOLO AÑADIR": el dbschema actual manda. El script:
//   - añade tablas y columnas nuevas que existan en la BBDD;
//   - añade a columnas existentes las propiedades detectadas que AÚN NO tengan;
//   - NUNCA borra tablas, columnas ni propiedades, ni sobrescribe las ya fijadas
//     (maincol, ordercol, primary, default, type json…).
// Si algo sobra o es incorrecto, se corrige a mano con revisión humana.
// En el proyecto de origen la versión anterior reescribía el fichero entero y se perdían
// maincol/ordercol/default puestos a mano (ver docs/aprendizajes.md).

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';

$useTest = in_array('--test', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
if ($useTest) {
    require $root . '/tests/bootstrap.php';
} else {
    require $root . '/src/Config/constants.php';
}

$overrides = require __DIR__ . '/regen_dbschema.config.php';

$pdo = new PDO(DNS, USERNAME, PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dbname = DBNAME;

// Esquema actual (fuente de verdad).
$dbschema = [];
require $root . '/src/dbschema.php';
$current = $dbschema;

$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = " . $pdo->quote($dbname) . " ORDER BY table_name")
    ->fetchAll(PDO::FETCH_COLUMN);

$schema = $current;
$added = [];
foreach ($tables as $table) {
    $t = strtolower($table);
    if (in_array($t, $overrides['ignore_tables'] ?? [], true)) continue;

    $pkStmt = $pdo->prepare("SELECT column_name FROM information_schema.key_column_usage WHERE table_schema=? AND table_name=? AND constraint_name='PRIMARY'");
    $pkStmt->execute([$dbname, $table]);
    $pkCols = array_map('strtolower', $pkStmt->fetchAll(PDO::FETCH_COLUMN));
    if (isset($overrides['primary'][$t])) $pkCols = array_map('strtolower', $overrides['primary'][$t]);

    $cols = $pdo->prepare("SELECT column_name, data_type, is_nullable, character_maximum_length, column_default, extra FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position");
    $cols->execute([$dbname, $table]);

    $mainCol  = $overrides['maincol'][$t] ?? null;
    $jsonCols = array_map('strtolower', $overrides['json'][$t] ?? []);

    if (!isset($schema[$t])) { $schema[$t] = []; $added[] = "tabla $t"; }
    foreach ($cols as $c) {
        $colName = strtolower($c['column_name']);
        $dataType = (in_array($colName, $jsonCols, true) || strtolower($c['data_type']) === 'json') ? 'json' : strtolower($c['data_type']);
        $meta = ['type' => $dataType, 'required' => $c['is_nullable'] === 'NO'];
        if ((int) ($c['character_maximum_length'] ?? 0) > 0 && $dataType !== 'json') $meta['length'] = (string) $c['character_maximum_length'];
        if (in_array($colName, $pkCols, true)) $meta['primary'] = true;
        if (stripos((string) $c['extra'], 'auto_increment') !== false) $meta['autoincrement'] = true;
        if ($mainCol === $colName) $meta['maincol'] = true;

        if (!isset($schema[$t][$colName])) {
            $schema[$t][$colName] = $meta;
            $added[] = "columna $t.$colName";
            continue;
        }
        // Solo añadir propiedades que falten; nunca sobrescribir.
        foreach ($meta as $k => $v) {
            if (!array_key_exists($k, $schema[$t][$colName])) {
                $schema[$t][$colName][$k] = $v;
                $added[] = "propiedad $t.$colName.$k";
            }
        }
    }
}

function exportvalue($v): string
{
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string) $v;
    if ($v === null) return 'null';
    return "'" . str_replace("'", "\\'", (string) $v) . "'";
}

$out = "<?php\n/**\n * Esquema de BBDD: fuente de verdad para ModelBase. Regla \"solo añadir\":\n * nunca se pierden propiedades fijadas a mano. Regenerado/ampliado con tools/regen_dbschema.php.\n */\n\$dbschema=[\n";
foreach ($schema as $table => $cols) {
    $out .= "\n\"{$table}\"=>[\n";
    foreach ($cols as $col => $meta) {
        $parts = [];
        foreach ($meta as $k => $v) $parts[] = "'$k' => " . exportvalue($v);
        $out .= "  '{$col}' =>[" . implode(', ', $parts) . ",],\n";
    }
    $out .= " ],\n";
}
$out .= "];\n";

echo count($added) === 0 ? "Sin cambios.\n" : "Añadido:\n - " . implode("\n - ", $added) . "\n";
if ($dryRun) { echo "\n(--dry-run: no se escribe nada)\n"; exit(0); }
if (count($added) > 0) {
    file_put_contents($root . '/src/dbschema.php', $out);
    echo "Escrito src/dbschema.php (" . count($schema) . " tablas). Revisa el diff antes de commitear.\n";
}
