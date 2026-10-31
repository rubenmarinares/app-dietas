<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ModelBase;
use PHPUnit\Framework\TestCase;

final class FieldsTestModel extends ModelBase
{
    static public $tablename = 'fieldstest';
}

final class NaturalKeyTestModel extends ModelBase
{
    static public $tablename = 'naturalkeytest';
}

final class CompositeKeyTestModel extends ModelBase
{
    static public $tablename = 'compositekeytest';
}

/** Doble de db: `$error` privada como en db; solo cuenta los SELECT para detectar consultas de más. */
final class InsertingDb
{
    private $error;
    public array $inserted = [];
    public int $selects = 0;
    public function __construct(private bool $ok = true) {}
    public function insert($table, $info) { $this->inserted[] = $info; return $this->ok; }
    public function lastInsertId() { return '42'; }
    public function runone($sql, $bind = []) { $this->selects++; return ['id' => '999', 'clave' => 'otra']; }
}

final class ModelBaseFieldsTest extends TestCase
{
    private $originalschema;

    protected function setUp(): void
    {
        $this->originalschema = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = [
            'fieldstest' => [
                'id' => ['type' => 'int', 'primary' => true, 'autoincrement' => true, 'required' => true],
                'nombre' => ['type' => 'varchar', 'length' => '5'],
                'ancho' => ['type' => 'int'],
                'datos' => ['type' => 'json'],
                'cuerpo' => ['type' => 'text', 'length' => '10'],
                'libre' => ['type' => 'varchar'],
            ],
            'naturalkeytest' => [
                'clave' => ['type' => 'varchar', 'primary' => true, 'length' => '50'],
            ],
            'compositekeytest' => [
                'a' => ['type' => 'int', 'primary' => true],
                'b' => ['type' => 'int', 'primary' => true],
            ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['dbschema'] = $this->originalschema;
    }

    public function testEnrichvaluesKeepsEmptyRowEmpty(): void
    {
        $this->assertSame([], FieldsTestModel::enrichvalues([]));
        $this->assertFalse(FieldsTestModel::enrichvalues(false));
        $this->assertSame(['id' => '1', 'datos' => ['a' => 1]], FieldsTestModel::enrichvalues(['id' => '1', 'datos' => '{"a":1}']));
    }

    public function testGetOfMissingIdReturnsEmptyArrayWithJsonColumns(): void
    {
        $db = new class {
            public function runone($sql, $params = []) { return []; }
            public function run($sql, $params = []) { return []; }
        };
        $this->assertSame([], FieldsTestModel::get($db, 999));
        $this->assertSame([], FieldsTestModel::get($db, ['id' => 999]));
    }

    public function testGetWithCompositePrimaryRequiresAnArray(): void
    {
        $db = new class {
            public function runone($sql, $params = []) { return []; }
        };
        $this->expectException(\InvalidArgumentException::class);
        CompositeKeyTestModel::get($db, 1);
    }

    public function testFilterfieldsKeepsExplicitNull(): void
    {
        $result = FieldsTestModel::filterfields(['nombre' => null, 'ancho' => null, 'datos' => null]);
        $this->assertArrayHasKey('nombre', $result);
        $this->assertNull($result['nombre']);
        $this->assertArrayHasKey('ancho', $result);
        $this->assertNull($result['ancho']);
        $this->assertArrayHasKey('datos', $result);
        $this->assertNull($result['datos'], 'null en columna json no debe codificarse como "null"');
    }

    public function testFilterfieldsDoesNotAddAbsentKeys(): void
    {
        $this->assertSame([], FieldsTestModel::filterfields([]));
        $this->assertSame(['ancho' => 3], FieldsTestModel::filterfields(['ancho' => '3']));
    }

    public function testFilterfieldsTruncatesVarcharByCharacters(): void
    {
        $result = FieldsTestModel::filterfields(['nombre' => 'ñañañaña']);
        $this->assertSame('ñañañ', $result['nombre']);
        $this->assertTrue(mb_check_encoding($result['nombre'], 'UTF-8'));
    }

    public function testValidateMeasuresLengthInCharacters(): void
    {
        $this->assertTrue(FieldsTestModel::validate(['nombre' => 'ñañañ'], false));
    }

    public function testValidateMeasuresTextLimitInBytes(): void
    {
        // validate() hace die ante un exceso: se ejecuta en un proceso aparte.
        // 'ñañaña' = 6 caracteres pero 9 bytes UTF-8; límite text = 8 bytes.
        $file = tempnam(sys_get_temp_dir(), 'mbv');
        file_put_contents($file, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . '$GLOBALS["dbschema"]=["fieldstest"=>["cuerpo"=>["type"=>"text","length"=>"8"]]];'
            . 'class M extends App\Core\ModelBase{static public $tablename="fieldstest";}'
            . 'var_dump(M::validate(["cuerpo"=>"ñañaña"],false));');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        unlink($file);
        $this->assertStringContainsString('cuerpo is too long', $out);
    }

    public function testFilterfieldsDoesNotTruncateVarcharWithoutLength(): void
    {
        $this->assertSame(['libre' => 'texto largo'], FieldsTestModel::filterfields(['libre' => 'texto largo']));
    }

    public function testInsertAutoincrementReturnsLastInsertId(): void
    {
        $db = new InsertingDb();
        $this->assertSame(42, FieldsTestModel::insert($db, ['nombre' => 'a']));
        $this->assertSame(0, $db->selects, 'sin SELECT aparte para adivinar el id');
    }

    public function testInsertNaturalPrimaryReturnsSuppliedValue(): void
    {
        $db = new InsertingDb();
        $this->assertSame('mia', NaturalKeyTestModel::insert($db, ['clave' => 'mia']));
        $this->assertSame(0, $db->selects);
    }

    public function testInsertCompositePrimaryReturnsThePrimaries(): void
    {
        $db = new InsertingDb();
        $this->assertSame(['a' => 1, 'b' => 2], CompositeKeyTestModel::insert($db, ['a' => 1, 'b' => 2]));
        $this->assertSame(0, $db->selects);
    }

    public function testInsertFailureReturnsFalseWithoutTouchingPrivateError(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'mbl');
        $previous = ini_set('error_log', $log);
        try {
            $this->assertFalse(FieldsTestModel::insert(new InsertingDb(false), ['nombre' => 'a']));
            $this->assertStringContainsString('fieldstest', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
    }
}
