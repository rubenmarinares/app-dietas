<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testMake(): void
    {
        $this->assertSame('reunion-anual-2026', Slug::make('Reunión  anual 2026!'));
        $this->assertSame('5o-congreso-nacional', Slug::make('5º Congreso Nacional'));
        $this->assertSame('a-b', Slug::make('--a---b--'));
        $this->assertSame('que-pasa-nino', Slug::make('¿Qué pasa, Niño?'));
        $this->assertSame('ano-ultimo-pinguino', Slug::make('Año ÚLTIMO pingüino'));
    }

    public function testMakeMayReturnEmpty(): void
    {
        $this->assertSame('', Slug::make('¿?!'));
    }

    public function testUniqueEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Slug::unique('', fn($s) => false);
    }

    public function testUnique(): void
    {
        $taken = ['x', 'x-2'];
        $this->assertSame('x-3', Slug::unique('x', fn($s) => in_array($s, $taken, true)));
        $this->assertSame('y', Slug::unique('y', fn($s) => false));
    }
}
