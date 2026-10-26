<?php declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * dbschema.php ↔ BBDD real (BBDD de test con migraciones aplicadas).
 * Caza tablas/columnas que faltan y propiedades (primary, json) mal puestas.
 * Antes vivía en tests/Unit pero necesita BBDD: es de integración.
 */
class DbSchemaValidityTest extends TestCase
{
    private static array $schema;
    private static ?\PDO $db = null;
    private static string $dbname;

    public static function setUpBeforeClass(): void
    {
        require __DIR__ . '/../../src/dbschema.php';
        self::$schema = $dbschema;
        self::$dbname = DBNAME;
        if (testdbavailable()) self::$db = new \PDO(DNS, USERNAME, PASSWORD);
    }

    protected function setUp(): void
    {
        if (self::$db === null) $this->markTestSkipped('BBDD de test no disponible.');
    }

    private function realtables(): array
    {
        return array_map('strtolower', self::$db->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = " . self::$db->quote(self::$dbname)
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEveryTableUsedByModelsIsInSchema(): void
    {
        $modelTables = [];
        foreach (glob(__DIR__ . '/../../src/Domain/Models/*.php') as $file) {
            if (preg_match('/\$tablename\s*=\s*[\'"]([^\'"]+)[\'"]/', file_get_contents($file), $m)) {
                $modelTables[] = strtolower($m[1]);
            }
        }
        $missing = array_values(array_diff(array_unique($modelTables), array_map('strtolower', array_keys(self::$schema))));
        $this->assertSame([], $missing, 'Tablas referenciadas por modelos sin entry en dbschema: ' . implode(', ', $missing));
    }

    public function testEverySchemaTableExistsInDatabase(): void
    {
        $missing = array_values(array_diff(array_keys(self::$schema), $this->realtables()));
        $this->assertSame([], $missing, 'Tablas en dbschema que no existen en BBDD: ' . implode(', ', $missing));
    }

    public function testEverySchemaColumnExistsInDatabase(): void
    {
        $errors = [];
        foreach (self::$schema as $table => $cols) {
            $stmt = self::$db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=?");
            $stmt->execute([self::$dbname, $table]);
            $real = array_map('strtolower', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            foreach (array_keys($cols) as $col) {
                if (!in_array(strtolower($col), $real, true)) $errors[] = "$table.$col";
            }
        }
        $this->assertSame([], $errors, 'Columnas en dbschema que no existen en BBDD: ' . implode(', ', $errors));
    }

    public function testPrimaryKeyColumnsAreMarked(): void
    {
        $all = [];
        foreach (self::$schema as $table => $cols) {
            $realPks = array_map('strtolower', self::$db->query(
                "SELECT column_name FROM information_schema.key_column_usage
                  WHERE table_schema = " . self::$db->quote(self::$dbname) . "
                    AND table_name = " . self::$db->quote($table) . "
                    AND constraint_name = 'PRIMARY'"
            )->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($realPks as $pkCol) {
                if (empty($cols[$pkCol]['primary'])) $all[] = "$table.$pkCol: PK real no marcada 'primary' => true";
            }
            foreach ($cols as $colName => $meta) {
                if (!empty($meta['primary']) && !in_array($colName, $realPks, true)) $all[] = "$table.$colName: marcada primary pero NO es PK real";
            }
        }
        $this->assertSame([], $all, implode("\n", $all));
    }

    public function testJsonColumnsHaveCompatibleType(): void
    {
        $errors = [];
        foreach (self::$schema as $table => $cols) {
            foreach ($cols as $colName => $meta) {
                if (($meta['type'] ?? '') !== 'json') continue;
                $real = self::$db->prepare("SELECT data_type FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?");
                $real->execute([self::$dbname, $table, $colName]);
                $type = strtolower((string) $real->fetchColumn());
                // MariaDB expone JSON como longtext.
                if (!in_array($type, ['json', 'longtext', 'mediumtext', 'text'], true)) $errors[] = "$table.$colName ($type)";
            }
        }
        $this->assertSame([], $errors, 'Columnas json con tipo real incompatible: ' . implode(', ', $errors));
    }
}
