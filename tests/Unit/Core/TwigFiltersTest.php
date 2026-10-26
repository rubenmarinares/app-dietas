<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\TwigMyExtensions;
use PHPUnit\Framework\TestCase;

// TwigMyExtensions vive en el mismo fichero que ScreenBase (no tiene autoload propio).
require_once __DIR__ . '/../../../src/Core/ScreenBase.php';

class TwigFiltersTest extends TestCase
{
    private TwigMyExtensions $ext;

    protected function setUp(): void
    {
        $this->ext = new TwigMyExtensions();
    }

    public function testEveryFilterPointsToAnExistingMethod(): void
    {
        // Antes: 'json_decode' → JsonDecode (el método era JonDecode) y 'monedaaentero' → ',monedaaEntero'.
        foreach ($this->ext->getFilters() as $filter) {
            $callable = $filter->getCallable();
            $this->assertTrue(is_callable($callable), 'Filtro sin método: ' . $filter->getName());
        }
    }

    public function testMoneyIsIntegerTimesTenThousand(): void
    {
        $this->assertSame(12345000, $this->ext->monedaaEntero('1.234,5'));
        $this->assertSame(15000, $this->ext->monedaaEntero('1,5'));
        $this->assertSame(-5000, $this->ext->monedaaEntero('-0,5'));
        $this->assertSame('1.234,50', $this->ext->monedaaTexto(12345000));
        $this->assertSame('1,234.50', $this->ext->monedaaTextous(12345000));
    }

    public function testDateFilters(): void
    {
        $this->assertSame('20/10/2026', $this->ext->dateSqltoFrontend('2026-10-20'));
        $this->assertSame('20/10/2026', $this->ext->dateIntToFrontend(20261020));
        $this->assertSame(20261020, $this->ext->dateToInt('20/10/2026'));
        $this->assertSame('20261020', $this->ext->dateSqltoInt('2026-10-20'));
        $this->assertSame('', $this->ext->dateIntToFrontend(0));
    }

    public function testJsonDecode(): void
    {
        $this->assertSame(['a' => 1], $this->ext->jsondecode('{"a":1}'));
        $this->assertNull($this->ext->jsondecode(null));
    }
}
