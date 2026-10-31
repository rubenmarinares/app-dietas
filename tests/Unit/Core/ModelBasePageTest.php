<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ModelBase;
use PHPUnit\Framework\TestCase;

final class PagedPaginaModel extends ModelBase
{
    static public $tablename = 'paginas';
    static public $defaultorder = 'paginas.fechapublicacion DESC';
    static public $joins = [
        ['areas', 'idarea', 'idarea', ['area'], '', '', ''],
        ['areas', 'idpadre', 'idarea', ['area AS areapadre'], 'ap', 'areas', ''],
    ];
}

final class ShortJoinPaginaModel extends ModelBase
{
    static public $tablename = 'paginas';
    static public $defaultorder = 'paginas.fechapublicacion DESC';
    // Formato corto: alias, tabla origen y condición extra son opcionales.
    static public $joins = [['areas', 'idarea', 'idarea', ['area']]];
}

/** Doble de db: captura el SQL y devuelve un total fijo. */
final class CapturingDb
{
    public array $sql = [];
    public function __construct(private int $total) {}
    public function runone($sql, $bind = []) { $this->sql[] = $sql; return ['total' => (string) $this->total]; }
    public function run($sql, $bind = []) { $this->sql[] = $sql; return [['idpagina' => '1']]; }
}

final class ModelBasePageTest extends TestCase
{
    private $originalschema;

    protected function setUp(): void
    {
        $this->originalschema = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = [
            'paginas' => [
                'idpagina' => ['type' => 'int', 'primary' => true],
                'idarea' => ['type' => 'int'],
                'titulo' => ['type' => 'varchar'],
                'fechapublicacion' => ['type' => 'int'],
            ],
            'areas' => [
                'idarea' => ['type' => 'int', 'primary' => true],
                'idpadre' => ['type' => 'int'],
                'area' => ['type' => 'varchar'],
                'orden' => ['type' => 'int'],
            ],
        ];
        // Un "Undefined array key" debe hacer fallar el test, no solo ensuciar la salida.
        set_error_handler(function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $GLOBALS['dbschema'] = $this->originalschema;
    }

    private function select(CapturingDb $db): string
    {
        return (string) end($db->sql);
    }

    public function testPaginaTresDeDiezUsaLimitYOffset(): void
    {
        $db = new CapturingDb(95);
        $r = PagedPaginaModel::searchpage($db, [], 3, 10);

        $this->assertStringEndsWith('LIMIT 10 OFFSET 20', trim($this->select($db)));
        $this->assertSame(95, $r['total']);
        $this->assertSame(3, $r['page']);
        $this->assertSame(10, $r['pages']);
        $this->assertCount(1, $r['items']);
    }

    public function testPaginaSeAcotaAlRango(): void
    {
        $db = new CapturingDb(15);
        $this->assertSame(2, PagedPaginaModel::searchpage($db, [], 9, 10)['page']);
        $this->assertStringEndsWith('LIMIT 10 OFFSET 10', trim($this->select($db)));

        $db = new CapturingDb(0);
        $r = PagedPaginaModel::searchpage($db, [], -4, 10);
        $this->assertSame(1, $r['page']);
        $this->assertSame(1, $r['pages']);
        $this->assertStringEndsWith('LIMIT 10 OFFSET 0', trim($this->select($db)));
    }

    public function testOrdenValidoSeAcepta(): void
    {
        $db = new CapturingDb(5);
        PagedPaginaModel::searchpage($db, [], 1, 10, 'paginas.titulo DESC');
        $this->assertStringContainsString('ORDER BY paginas.titulo DESC', $this->select($db));

        PagedPaginaModel::searchpage($db, [], 1, 10, 'ap.orden ASC, areas.orden, titulo');
        $this->assertStringContainsString('ORDER BY ap.orden ASC, areas.orden ASC, paginas.titulo ASC', $this->select($db));
    }

    public function testOrdenInvalidoUsaElDefaultorder(): void
    {
        $db = new CapturingDb(5);
        foreach (['titulo; DROP', 'paginas.nope DESC', 'users.email', 'titulo DESC, 1=1', 'titulo RANDOM', 'areas.area) DESC'] as $order) {
            PagedPaginaModel::searchpage($db, [], 1, 10, $order);
            $this->assertStringContainsString('ORDER BY paginas.fechapublicacion DESC', $this->select($db), $order);
        }
    }

    public function testSafeorderToleraJoinsSinIndicesOpcionales(): void
    {
        $this->assertSame('areas.orden DESC', ShortJoinPaginaModel::safeorder('areas.orden DESC'));
        $this->assertNull(ShortJoinPaginaModel::safeorder('ap.orden'));

        $db = new CapturingDb(1);
        ShortJoinPaginaModel::searchpage($db, [], 1, 10, 'areas.orden');
        $this->assertStringContainsString('ORDER BY areas.orden ASC', $this->select($db));
    }

    public function testSearchConOrdenValidoLoAplica(): void
    {
        $db = new CapturingDb(0);
        $rows = PagedPaginaModel::search($db, [], 'paginas.titulo DESC, ap.orden');
        $this->assertStringContainsString('ORDER BY paginas.titulo DESC, ap.orden ASC', $this->select($db));
        $this->assertStringNotContainsString('fechapublicacion DESC', $this->select($db));
        $this->assertSame([['idpagina' => '1']], $rows, 'sigue siendo una lista simple de filas');
    }

    public function testSearchConOrdenInvalidoUsaElDefaultorder(): void
    {
        $db = new CapturingDb(0);
        foreach (['titulo; DROP', 'paginas.nope DESC', 'users.email'] as $order) {
            PagedPaginaModel::search($db, [], $order);
            $this->assertStringEndsWith('ORDER BY paginas.fechapublicacion DESC', trim($this->select($db)), $order);
        }
    }

    public function testSearchSinOrdenUsaElDefaultorder(): void
    {
        $db = new CapturingDb(0);
        PagedPaginaModel::search($db);
        $this->assertStringEndsWith('ORDER BY paginas.fechapublicacion DESC', trim($this->select($db)));
        PagedPaginaModel::search($db, [], '');
        $this->assertStringEndsWith('ORDER BY paginas.fechapublicacion DESC', trim($this->select($db)));
    }

    public function testUserSearchPropagaElOrden(): void
    {
        $GLOBALS['dbschema']['users'] = ['iduser' => ['type' => 'int', 'primary' => true], 'email' => ['type' => 'varchar']];
        $db = new class {
            public array $sql = [];
            public function run($sql, $bind = []) { $this->sql[] = $sql; return []; }
        };
        \App\Domain\Models\User::search($db, [], 'users.email DESC');
        $this->assertStringContainsString('ORDER BY users.email DESC', $db->sql[0]);
    }
}
