<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ModelBase;
use App\Core\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationConsistencyModel extends ModelBase
{
    static public $tablename = 'paginas';
}

/** Doble de db: devuelve un total fijo, como en ModelBasePageTest. */
final class PaginationFakeDb
{
    public function __construct(private int $total) {}
    public function runone($sql, $bind = []) { return ['total' => (string) $this->total]; }
    public function run($sql, $bind = []) { return []; }
}

/** Las páginas empiezan en 1, igual que ModelBase::searchpage. */
final class PaginationTest extends TestCase
{
    public function testcalcfirstpage(): void
    {
        $this->assertSame(['current' => 1, 'pages' => 14, 'prev' => null, 'next' => 2], Pagination::calc(107, 8, 1));
    }

    public function testcalcmiddlepage(): void
    {
        $this->assertSame(['current' => 5, 'pages' => 14, 'prev' => 4, 'next' => 6], Pagination::calc(107, 8, 5));
    }

    public function testcalclastpage(): void
    {
        $this->assertSame(['current' => 14, 'pages' => 14, 'prev' => 13, 'next' => null], Pagination::calc(107, 8, 14));
    }

    public function testcalcclampstoolarge(): void
    {
        $this->assertSame(['current' => 14, 'pages' => 14, 'prev' => 13, 'next' => null], Pagination::calc(107, 8, 99));
    }

    public function testcalcclampszeroandnegative(): void
    {
        $first = ['current' => 1, 'pages' => 14, 'prev' => null, 'next' => 2];
        $this->assertSame($first, Pagination::calc(107, 8, 0));
        $this->assertSame($first, Pagination::calc(107, 8, -3));
    }

    public function testcalcemptyhasonepage(): void
    {
        $one = ['current' => 1, 'pages' => 1, 'prev' => null, 'next' => null];
        $this->assertSame($one, Pagination::calc(0, 8, 1));
        $this->assertSame($one, Pagination::calc(0, 8, 5));
        $this->assertSame($one, Pagination::calc(0, 8, 0));
        $this->assertSame($one, Pagination::calc(-5, 8, 1));
    }

    public function testcalczeroperpagedoesnotdividebyzero(): void
    {
        $this->assertSame(5, Pagination::calc(5, 0, 1)['pages'], 'perpage < 1 se trata como 1');
    }

    public function testlinksusetherealpagenumber(): void
    {
        $links = Pagination::links('/novedades/', Pagination::calc(40, 8, 3));
        $this->assertSame(3, $links['current']);
        $this->assertSame('/novedades/?pag=2', $links['prevurl']);
        $this->assertSame('/novedades/?pag=4', $links['nexturl']);
        $this->assertSame('/novedades/?pag=3', $links['currenturl']);
        $this->assertSame(['/novedades/', '/novedades/?pag=2', '/novedades/?pag=3', '/novedades/?pag=4', '/novedades/?pag=5'], array_column($links['links'], 'url'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($links['links'], 'number'));
        $this->assertSame([false, false, true, false, false], array_column($links['links'], 'current'));
    }

    public function testlinksfirstpagehasnopagandnoprev(): void
    {
        $links = Pagination::links('/n/', Pagination::calc(40, 8, 1));
        $this->assertNull($links['prevurl']);
        $this->assertSame('/n/', $links['currenturl']);
        $this->assertSame('/n/?pag=2', $links['nexturl']);
    }

    public function testlinkssecondpagepointsprevtotheurlwithoutpag(): void
    {
        $this->assertSame('/n/', Pagination::links('/n/', Pagination::calc(40, 8, 2))['prevurl']);
    }

    public function testlinkslastpagehasnonext(): void
    {
        $this->assertNull(Pagination::links('/n/', Pagination::calc(40, 8, 5))['nexturl']);
    }

    public function testlinkskeepparamsandomitpageone(): void
    {
        $links = Pagination::links('/agenda/', Pagination::calc(16, 8, 2), ['historico' => '']);
        $this->assertSame('/agenda/?historico', $links['prevurl']);
        $this->assertNull($links['nexturl']);
        $this->assertSame('/agenda/?historico', $links['links'][0]['url']);
        $this->assertSame('/agenda/?historico&pag=2', $links['links'][1]['url']);
        $q = Pagination::links('/buscador', Pagination::calc(30, 10, 1), ['q' => 'a b&c']);
        $this->assertSame('/buscador?q=a%20b%26c', $q['currenturl']);
        $this->assertSame('/buscador?q=a%20b%26c&pag=2', $q['nexturl']);
    }

    public function testlinkswindowatstart(): void
    {
        $links = Pagination::links('/n/', Pagination::calc(800, 8, 1));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($links['links'], 'number'));
        $this->assertSame('/n/', $links['links'][0]['url']);
    }

    public function testlinkswindowinthemiddle(): void
    {
        $links = Pagination::links('/n/', Pagination::calc(800, 8, 51));
        $this->assertSame([48, 49, 50, 51, 52, 53, 54], array_column($links['links'], 'number'), 'ventana de 7 alrededor de la actual');
        $this->assertSame('/n/?pag=48', $links['links'][0]['url']);
        $this->assertTrue($links['links'][3]['current']);
    }

    public function testlinkswindowatend(): void
    {
        $links = Pagination::links('/n/', Pagination::calc(800, 8, 100));
        $this->assertSame([94, 95, 96, 97, 98, 99, 100], array_column($links['links'], 'number'));
        $this->assertSame('/n/?pag=100', $links['links'][6]['url']);
    }

    public function testlinkswithfewerpagesthanthewindow(): void
    {
        $this->assertSame([1, 2, 3], array_column(Pagination::links('/n/', Pagination::calc(24, 8, 2))['links'], 'number'));
    }

    public function testlinksnumberalwaysmatchesthepaginurl(): void
    {
        foreach (Pagination::links('/n/', Pagination::calc(800, 8, 51))['links'] as $link) {
            $this->assertSame('/n/?pag=' . $link['number'], $link['url']);
        }
    }

    public function testparamreadsnumbers(): void
    {
        $this->assertSame(3, Pagination::param(['pag' => '3']));
        $this->assertSame(3, Pagination::param(['pag' => 3]));
        $this->assertSame(3, Pagination::param(['pag' => ' 3 ']));
    }

    public function testparamgarbageisone(): void
    {
        $this->assertSame(1, Pagination::param([]), 'sin pag');
        foreach (['', 'abc', '2x', '1.5', ['2'], null, true, '9999999999999'] as $garbage) {
            $this->assertSame(1, Pagination::param(['pag' => $garbage]), var_export($garbage, true));
        }
    }

    public function testparamzeronegativeandexcessendupclampedbycalc(): void
    {
        $this->assertSame(1, Pagination::calc(40, 8, Pagination::param(['pag' => '0']))['current']);
        $this->assertSame(1, Pagination::calc(40, 8, Pagination::param(['pag' => '-3']))['current']);
        $this->assertSame(5, Pagination::calc(40, 8, Pagination::param(['pag' => '99']))['current']);
    }

    public function testpagoneandnopagarethesamepage(): void
    {
        $withpag = Pagination::calc(40, 8, Pagination::param(['pag' => '1']));
        $nopag = Pagination::calc(40, 8, Pagination::param([]));
        $this->assertSame($nopag, $withpag);
        $this->assertSame(1, $nopag['current']);
        $this->assertSame(
            Pagination::links('/n/', $nopag),
            Pagination::links('/n/', $withpag)
        );
    }

    /** @return array<string,array{int,int,int}> */
    static public function searchpagecases(): array
    {
        return [
            'primera' => [95, 10, 1],
            'intermedia' => [95, 10, 3],
            'ultima' => [95, 10, 10],
            'excesiva' => [95, 10, 99],
            'cero' => [95, 10, 0],
            'negativa' => [95, 10, -4],
            'sin filas' => [0, 10, 1],
            'sin filas y excesiva' => [0, 10, 5],
            'perpage cero' => [5, 0, 3],
        ];
    }

    #[DataProvider('searchpagecases')]
    public function testsamepageassearchpage(int $total, int $perpage, int $page): void
    {
        $original = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = ['paginas' => ['idpagina' => ['type' => 'int', 'primary' => true]]];
        try {
            $searchpage = PaginationConsistencyModel::searchpage(new PaginationFakeDb($total), [], $page, $perpage);
        } finally {
            $GLOBALS['dbschema'] = $original;
        }
        $calc = Pagination::calc($total, $perpage, $page);
        $this->assertSame($searchpage['page'], $calc['current']);
        $this->assertSame($searchpage['pages'], $calc['pages']);
    }
}
