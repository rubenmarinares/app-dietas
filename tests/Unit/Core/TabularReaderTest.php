<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\TabularReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/** Lectura de hojas .xlsx/.csv para importaciones (ficheros creados al vuelo en el temporal). */
final class TabularReaderTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
    }

    private function csv(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tr') . '.csv';
        file_put_contents($path, $bytes);
        return $this->files[] = $path;
    }

    /** @param array<int, array<int, mixed>> $cells filas (1-based) → valores por columna desde A */
    private function xlsx(array $cells): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        foreach ($cells as $row => $values) {
            foreach ($values as $i => $v) {
                if ($v !== null) $sheet->setCellValue([$i + 1, $row], $v);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'tr') . '.xlsx';
        (new Xlsx($book))->save($path);
        return $this->files[] = $path;
    }

    public function testSupportsOnlySpreadsheetExtensions(): void
    {
        $this->assertTrue(TabularReader::supports('a.XLSX'));
        $this->assertTrue(TabularReader::supports('lista.xls'));
        $this->assertTrue(TabularReader::supports('a.csv'));
        $this->assertFalse(TabularReader::supports('a.pdf'));
        $this->assertFalse(TabularReader::supports('csv'));
    }

    public function testHeaderIsFirstNonEmptyRowAndRowNumbersAreSheetRows(): void
    {
        $res = TabularReader::read($this->xlsx([1 => ['Listado'], 3 => ['Email', 'Nombre'], 4 => ['a@b.es', 'Ana']]), 'xlsx');
        $this->assertSame(['Email', 'Nombre'], $res['headers']);
        $this->assertSame(4, $res['rows'][0]['fila']);
        $this->assertSame(['Email' => 'a@b.es', 'Nombre' => 'Ana'], $res['rows'][0]['values']);
        $this->assertSame(1, $res['total']);
    }

    public function testBlankAndRepeatedHeaders(): void
    {
        $res = TabularReader::read($this->csv("Email;;Email\na;b;c\n"), 'csv');
        $this->assertSame(['Email', 'Columna 2', 'Email (2)'], $res['headers']);
        $this->assertSame(['Email' => 'a', 'Columna 2' => 'b', 'Email (2)' => 'c'], $res['rows'][0]['values']);
    }

    public function testShortRowsArePaddedAndEmptyRowsSkipped(): void
    {
        $res = TabularReader::read($this->xlsx([1 => ['Email', 'Nombre'], 2 => ['a@b.es'], 4 => ['c@d.es', 'Carla']]), 'xlsx');
        $this->assertSame(2, $res['total']);
        $this->assertSame(['Email' => 'a@b.es', 'Nombre' => ''], $res['rows'][0]['values']);
        $this->assertSame(4, $res['rows'][1]['fila']);
    }

    public function testNumericCellsAreReadAsDisplayedText(): void
    {
        $res = TabularReader::read($this->xlsx([1 => ['Telefono', 'DNI'], 2 => [612345678, 12345678]]), 'xlsx');
        $this->assertSame('612345678', $res['rows'][0]['values']['Telefono']);
        $this->assertSame('12345678', $res['rows'][0]['values']['DNI']);
    }

    public function testCsvWindows1252SemicolonAndBom(): void
    {
        $res = TabularReader::read($this->csv("\xEF\xBB\xBFNombre;Email\nJos\xE9;j@x.es\n"), 'csv');
        $this->assertSame(['Nombre', 'Email'], $res['headers']);
        $this->assertSame('José', $res['rows'][0]['values']['Nombre']);

        $res = TabularReader::read($this->csv("Nombre,Email\nMar\xEDa,m@x.es\n"), 'csv');
        $this->assertSame('María', $res['rows'][0]['values']['Nombre']);
        $this->assertSame('m@x.es', $res['rows'][0]['values']['Email']);
    }

    public function testCsvQuotedDelimiter(): void
    {
        $res = TabularReader::read($this->csv("Nombre,Centro\nAna,\"Centro, Norte\"\n"), 'csv');
        $this->assertSame('Centro, Norte', $res['rows'][0]['values']['Centro']);
    }

    public function testEmptyFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('El archivo está vacío.');
        TabularReader::read($this->csv("\n\n"), 'csv');
    }

    public function testUnsupportedExtensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Formato no admitido (usa .xlsx, .xls o .csv).');
        TabularReader::read($this->csv("a\n"), 'pdf');
    }

    public function testReadFilterBoundsRowsAndColumns(): void
    {
        $f = TabularReader::readfilter(5);
        $last = 5 + TabularReader::ROWMARGIN;
        $this->assertTrue($f->readCell('A', 1));
        $this->assertTrue($f->readCell('A', $last));
        $this->assertFalse($f->readCell('A', $last + 1), 'no carga filas de más: memoria acotada');
        $this->assertTrue($f->readCell('CV', 1), 'columna 100');
        $this->assertFalse($f->readCell('CW', 1), 'columna 101');
        $this->assertTrue(TabularReader::readfilter(0)->readCell('A', 100000), 'sin tope de filas');
    }

    public function testXlsxOverTheCapStillReportsTotal(): void
    {
        $cells = [1 => ['Email']];
        for ($i = 2; $i <= 31; $i++) $cells[$i] = ['p' . $i . '@x.es'];
        $res = TabularReader::read($this->xlsx($cells), 'xlsx', 5);
        $this->assertCount(5, $res['rows']);
        $this->assertSame(30, $res['total']);

        $cells = [1 => ['Email']];
        for ($i = 2; $i <= 101; $i++) $cells[$i] = ['p' . $i . '@x.es'];
        $res = TabularReader::read($this->xlsx($cells), 'xlsx', 5);
        $this->assertCount(5, $res['rows']);
        $this->assertSame(100, $res['total'], 'las filas más allá del filtro también cuentan');
    }

    public function testMaxRowsStopsStoringButCountsTotal(): void
    {
        $res = TabularReader::read($this->csv("Email\na\nb\nc\n"), 'csv', 2);
        $this->assertCount(2, $res['rows']);
        $this->assertSame(3, $res['total']);
    }
}
