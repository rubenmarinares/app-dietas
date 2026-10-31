<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\TabularExport;
use App\Core\TabularReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exportación CSV/XLSX: partes puras y ficheros escritos en el temporal (send() solo se comprueba hasta la validación). */
final class TabularExportTest extends TestCase
{
    private const COLUMNS = ['nombre' => 'Nombre', 'telefono' => 'Teléfono', 'importe' => 'Importe'];

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
    }

    private function tempfile(string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'te');
        @unlink($path);
        return $this->files[] = $path . '.' . $ext;
    }

    /** @return array{0: int, 1: string, 2: string} filas escritas, ruta, contenido */
    private function export(string $format, array $columns, iterable $rows): array
    {
        $path = $this->tempfile($format);
        $n = TabularExport::write($format, $path, $columns, $rows);
        return [$n, $path, (string) file_get_contents($path)];
    }

    private function sheetxml(string $path): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        return $xml;
    }

    private function loadsheet(string $path): Spreadsheet
    {
        return IOFactory::createReader('Xlsx')->load($path);
    }

    // ---- celda CSV ----

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function injectionprovider(): array
    {
        return [
            'igual' => ['=1+1', "'=1+1"],
            'guion' => ['-2+3', "'-2+3"],
            'arroba' => ['@SUM(A1)', "'@SUM(A1)"],
            'tabulador' => ["\t=1", "'\t=1"],
            'mas seguido de letra' => ['+cmd', "'+cmd"],
            'mas solo' => ['+', "'+"],
            'hyperlink' => ['=HYPERLINK("http://x")', "'=HYPERLINK(\"http://x\")"],
        ];
    }

    #[DataProvider('injectionprovider')]
    public function testCsvcellNeutralizesFormulas(string $value, string $expected): void
    {
        $this->assertSame($expected, TabularExport::csvcell($value));
    }

    public function testCsvcellKeepsPhonesAndPlainText(): void
    {
        $this->assertSame('+34 600 123 456', TabularExport::csvcell('+34 600 123 456'));
        $this->assertSame('600123456', TabularExport::csvcell('600123456'));
        $this->assertSame('María = Ana', TabularExport::csvcell('María = Ana'));
        $this->assertSame('', TabularExport::csvcell(''));
    }

    public function testCsvcellTurnsNewlinesIntoASpace(): void
    {
        $this->assertSame('a b c d', TabularExport::csvcell("a\r\nb\rc\nd"));
    }

    public function testCsvcellExemptsRealNumbersButNotNumericStrings(): void
    {
        $this->assertSame('-5', TabularExport::csvcell(-5));
        $this->assertSame('-1,5', TabularExport::csvcell(-1.5), 'decimal con coma para Excel en español');
        $this->assertSame('1234,5', TabularExport::csvcell(1234.5));
        $this->assertSame("'-5", TabularExport::csvcell('-5'), 'una cadena numérica sigue siendo texto');
    }

    public function testCsvcellEmptiesWhatIsNotAScalarOrAFiniteNumber(): void
    {
        $this->assertSame('', TabularExport::csvcell(null));
        $this->assertSame('', TabularExport::csvcell(['x']));
        $this->assertSame('', TabularExport::csvcell(new \stdClass()));
        $this->assertSame('', TabularExport::csvcell(NAN));
        $this->assertSame('', TabularExport::csvcell(INF));
    }

    // ---- línea CSV ----

    public function testCsvlineQuotesOnlyWhenNeeded(): void
    {
        $this->assertSame("a;b\r\n", TabularExport::csvline(['a', 'b']));
        $this->assertSame("\"a;b\";\"c d\";\"e\"\"f\"\r\n", TabularExport::csvline(['a;b', 'c d', 'e"f']));
    }

    public function testCsvlineProtectsEveryCell(): void
    {
        $this->assertSame("'=1;-5;\"'- x\"\r\n", TabularExport::csvline(['=1', -5, '- x']));
    }

    // ---- fichero CSV ----

    public function testCsvHasBomHeaderAndCrlf(): void
    {
        [$n, , $bytes] = $this->export('csv', self::COLUMNS, [['nombre' => 'Ana', 'telefono' => '+34 600', 'importe' => -3]]);
        $this->assertSame(1, $n);
        $this->assertSame("\xEF\xBB\xBFNombre;Teléfono;Importe\r\nAna;\"+34 600\";-3\r\n", $bytes);
    }

    public function testCsvAcceptsAGeneratorAndConsumesItLazily(): void
    {
        $seen = 0;
        $rows = (function () use (&$seen) {
            for ($i = 1; $i <= 3; $i++) {
                $seen++;
                yield ['nombre' => 'U' . $i];
            }
        })();
        $this->assertSame(0, $seen);
        [$n, , $bytes] = $this->export('csv', ['nombre' => 'Nombre'], $rows);
        $this->assertSame(3, $n);
        $this->assertSame(3, $seen);
        $this->assertStringEndsWith("U1\r\nU2\r\nU3\r\n", $bytes);
    }

    public function testOnlyDeclaredColumnsAreExportedAndMissingKeysStayEmpty(): void
    {
        [, , $bytes] = $this->export('csv', ['nombre' => 'Nombre', 'email' => 'Email'], [['password' => 'secreto', 'nombre' => 'Ana']]);
        $this->assertStringNotContainsString('secreto', $bytes);
        $this->assertStringEndsWith("Ana;\r\n", $bytes);
    }

    public function testRowsMayBeListsOrObjects(): void
    {
        [, , $bytes] = $this->export('csv', ['A', 'B'], [['x', 'y'], (object) ['0' => 'p', '1' => 'q']]);
        $this->assertStringEndsWith("x;y\r\np;q\r\n", $bytes);
    }

    public function testHeaderLabelsAreProtectedToo(): void
    {
        [, , $bytes] = $this->export('csv', ['a' => '=Total'], []);
        $this->assertSame("\xEF\xBB\xBF'=Total\r\n", $bytes);
    }

    public function testEmptyRowsCsvIsJustTheHeaderAndReadsBack(): void
    {
        [$n, $path] = $this->export('csv', self::COLUMNS, []);
        $this->assertSame(0, $n);
        $read = TabularReader::read($path, 'csv');
        $this->assertSame(['Nombre', 'Teléfono', 'Importe'], $read['headers']);
        $this->assertSame(0, $read['total']);
    }

    public function testCsvRoundTripWithTabularReader(): void
    {
        [, $path] = $this->export('csv', self::COLUMNS, [['nombre' => 'Núñez; "Ana"', 'telefono' => '+34 600', 'importe' => 12.5]]);
        $read = TabularReader::read($path, 'csv');
        $this->assertSame(['Nombre' => 'Núñez; "Ana"', 'Teléfono' => '+34 600', 'Importe' => '12,5'], $read['rows'][0]['values']);
    }

    // ---- XLSX ----

    public function testXlsxFormulasStayLiteralText(): void
    {
        $evil = ['=1+1', '=HYPERLINK("http://x","y")', '+1+cmd', '-2+3', '@SUM(A1)', "\t=1"];
        $rows = array_map(static fn($v) => ['a' => $v], $evil);
        [$n, $path] = $this->export('xlsx', ['a' => '=Cabecera'], $rows);
        $this->assertSame(count($evil), $n);

        $this->assertStringNotContainsString('<f>', $this->sheetxml($path), 'ninguna celda se guarda como fórmula');
        $book = $this->loadsheet($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame('=Cabecera', $sheet->getCell('A1')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        foreach ($evil as $i => $v) {
            $cell = $sheet->getCell('A' . ($i + 2));
            $this->assertSame($v, $cell->getValue());
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        }
        $book->disconnectWorksheets();
    }

    public function testXlsxKeepsTextAsTextAndRealNumbersAsNumbers(): void
    {
        [, $path] = $this->export('xlsx', self::COLUMNS, [
            ['nombre' => '007', 'telefono' => '612345678', 'importe' => -1234.5],
            ['nombre' => '1e5', 'telefono' => '+34 600', 'importe' => 3],
        ]);
        $book = $this->loadsheet($path);
        $sheet = $book->getActiveSheet();
        foreach (['A2' => '007', 'B2' => '612345678', 'A3' => '1e5', 'B3' => '+34 600'] as $coord => $v) {
            $this->assertSame($v, $sheet->getCell($coord)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coord)->getDataType());
        }
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
        $this->assertEqualsWithDelta(-1234.5, $sheet->getCell('C2')->getValue(), 0.0001);
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C3')->getDataType());
        $book->disconnectWorksheets();
    }

    public function testXlsxHeaderIsBoldAndColumnOptionsApply(): void
    {
        $columns = [
            'nombre' => 'Nombre',
            'importe' => ['label' => 'Importe', 'width' => 22, 'format' => '#,##0.00'],
        ];
        [, $path] = $this->export('xlsx', $columns, [['nombre' => 'Ana', 'importe' => 1.5], ['nombre' => 'Eva', 'importe' => 2]]);
        $book = $this->loadsheet($path);
        $sheet = $book->getActiveSheet();
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('B1')->getFont()->getBold());
        $this->assertFalse($sheet->getStyle('A2')->getFont()->getBold());
        $this->assertSame('Importe', $sheet->getCell('B1')->getValue());
        $this->assertEqualsWithDelta(22.0, $sheet->getColumnDimension('B')->getWidth(), 1.0);
        $this->assertSame('#,##0.00', $sheet->getStyle('B3')->getNumberFormat()->getFormatCode());
        $book->disconnectWorksheets();
    }

    public function testXlsxRoundTripWithTabularReader(): void
    {
        [, $path] = $this->export('xlsx', self::COLUMNS, [['nombre' => '=1+1', 'telefono' => '612345678', 'importe' => 7]]);
        $read = TabularReader::read($path, 'xlsx');
        $this->assertSame(['Nombre', 'Teléfono', 'Importe'], $read['headers']);
        $this->assertSame(['Nombre' => '=1+1', 'Teléfono' => '612345678', 'Importe' => '7'], $read['rows'][0]['values']);
    }

    public function testXlsxEmptyRowsIsJustTheHeader(): void
    {
        [$n, $path] = $this->export('xlsx', ['a' => 'Uno', 'b' => ['label' => 'Dos', 'format' => '0.00']], []);
        $this->assertSame(0, $n);
        $read = TabularReader::read($path, 'xlsx');
        $this->assertSame(['Uno', 'Dos'], $read['headers']);
        $this->assertSame(0, $read['total']);
    }

    public function testXlsxAcceptsAGenerator(): void
    {
        $rows = (static function () {
            yield ['a' => 'x'];
            yield ['a' => 'y'];
        })();
        [$n, $path] = $this->export('xlsx', ['a' => 'A'], $rows);
        $this->assertSame(2, $n);
        $this->assertSame(2, TabularReader::read($path, 'xlsx')['total']);
    }

    public function testXlsxSurvivesAwkwardValues(): void
    {
        [$n, $path] = $this->export('xlsx', ['a' => 'A'], [
            ['a' => "con\x01control"],
            ['a' => "bytes\xFFrotos"],
            ['a' => NAN],
            ['a' => INF],
            ['a' => null],
            ['a' => ['x']],
            ['a' => 'fin'],
        ]);
        $this->assertSame(7, $n);
        $book = $this->loadsheet($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame('', (string) $sheet->getCell('A4')->getValue(), 'NAN no llega al fichero');
        $this->assertSame('', (string) $sheet->getCell('A5')->getValue(), 'INF no llega al fichero');
        $this->assertSame('fin', $sheet->getCell('A8')->getValue());
        $book->disconnectWorksheets();
    }

    // ---- validación ----

    public function testUnknownFormatThrowsOnSendAndWrite(): void
    {
        foreach (['send', 'write'] as $method) {
            try {
                TabularExport::$method('pdf', 'x', ['a' => 'A'], []);
                $this->fail('Debió lanzar: ' . $method);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('pdf', $e->getMessage());
            }
        }
    }

    public function testFormatIsCaseInsensitive(): void
    {
        $path = $this->tempfile('csv');
        $this->assertSame(0, TabularExport::write('CSV', $path, ['a' => 'A'], []));
    }

    public function testEmptyColumnsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TabularExport::send('csv', 'x', [], []);
    }

    // ---- nombre y cabeceras ----

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function filenameprovider(): array
    {
        return [
            'normal' => ['asistentes-20261031', 'csv', 'asistentes-20261031.csv'],
            'extensión ya puesta' => ['datos.CSV', 'csv', 'datos.csv'],
            'extensión del otro formato' => ['datos.csv', 'xlsx', 'datos.xlsx'],
            'ruta' => ['../../etc/passwd', 'xlsx', 'etc-passwd.xlsx'],
            'barra invertida' => ['..\\..\\x', 'csv', 'x.csv'],
            'inyección de cabecera' => ["a\r\nSet-Cookie: x=1", 'csv', 'a-Set-Cookie-x-1.csv'],
            'comillas' => ['a"; filename="b', 'csv', 'a-filename-b.csv'],
            'acentos' => ['Año 2026', 'csv', 'A-o-2026.csv'],
            'vacío' => ['', 'csv', 'export.csv'],
            'solo basura' => ['"\\/..', 'xlsx', 'export.xlsx'],
        ];
    }

    #[DataProvider('filenameprovider')]
    public function testFilenameIsSanitized(string $name, string $format, string $expected): void
    {
        $this->assertSame($expected, TabularExport::filename($name, $format));
    }

    public function testFilenameIsBounded(): void
    {
        $this->assertLessThanOrEqual(155, strlen(TabularExport::filename(str_repeat('a', 500), 'xlsx')));
    }

    public function testResponseHeaders(): void
    {
        $csv = TabularExport::responseheaders('csv', "a\r\nX: y");
        $this->assertContains('Content-Type: text/csv; charset=UTF-8', $csv);
        $this->assertContains('Content-Disposition: attachment; filename="a-X-y.csv"', $csv);
        $this->assertContains('Cache-Control: private, no-store', $csv);
        $this->assertContains('X-Content-Type-Options: nosniff', $csv);
        foreach ($csv as $line) $this->assertDoesNotMatchRegularExpression('~[\r\n]~', $line);

        $xlsx = TabularExport::responseheaders('xlsx', 'b');
        $this->assertContains('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx);
        $this->assertContains('Content-Disposition: attachment; filename="b.xlsx"', $xlsx);
    }

    // ---- registro ----

    public function testLoglineHasNoPersonalDataSlot(): void
    {
        $this->assertSame(
            "[2026-10-31 10:00:00] exportar=webusuarios formato=xlsx iduser=7 filas=12\n",
            TabularExport::logline('2026-10-31 10:00:00', 7, 'webusuarios', 'xlsx', 12)
        );
        $this->assertStringContainsString('iduser=-', TabularExport::logline('d', null, 'x', 'csv', 0));
    }

    public function testLoglineLabelCannotForgeLines(): void
    {
        $line = TabularExport::logline('d', 1, "x\nfalso iduser=9 ana@ejemplo.es", 'csv', 0);
        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertStringContainsString('exportar=x-falso-iduser-9-ana-ejemplo.es ', $line);
    }

    public function testLogAppends(): void
    {
        $file = $this->tempfile('log');
        TabularExport::log(3, 'uno', 'csv', 1, $file);
        TabularExport::log(3, 'dos', 'csv', 2, $file);
        $lines = file($file);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('exportar=dos', $lines[1]);
    }

    public function testLogNeverThrows(): void
    {
        $sink = $this->tempfile('errlog');
        $previous = ini_set('error_log', $sink);
        try {
            TabularExport::log(3, 'x', 'csv', 1, sys_get_temp_dir()); // un directorio: no se puede escribir
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString('exports.log', (string) file_get_contents($sink));
    }
}
