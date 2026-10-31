<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\UploadValidator;
use PHPUnit\Framework\TestCase;

final class UploadValidatorTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            if (is_file($f)) unlink($f);
        }
    }

    private function make(string $name, string $content, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upv');
        file_put_contents($tmp, $content);
        $this->files[] = $tmp;
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => $error];
    }

    private function png(): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(2, 3);
            ob_start();
            imagepng($im);
            return (string) ob_get_clean();
        }
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2/lbpAAAAEklEQVR4nGP4z8Dwn+E/AwwDAC/xBPtGHbQcAAAAAElFTkSuQmCC');
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0/Kids[]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public function testpng(): void
    {
        $r = UploadValidator::check($this->make('Foto.PNG', $this->png()), 1000000, false);
        $this->assertSame('imagen', $r['tipo']);
        $this->assertSame('image/png', $r['mime']);
        $this->assertSame('png', $r['ext']);
        $this->assertSame(2, $r['ancho']);
        $this->assertSame(3, $r['alto']);
        $this->assertGreaterThan(0, $r['tamano']);
    }

    public function testpdf(): void
    {
        $r = UploadValidator::check($this->make('a.pdf', $this->pdf()), 1000000, false);
        $this->assertSame('documento', $r['tipo']);
        $this->assertSame('application/pdf', $r['mime']);
        $this->assertNull($r['ancho']);
        $this->assertNull($r['alto']);
    }

    public function testphprejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tipo de fichero no permitido');
        UploadValidator::check($this->make('x.php', '<?php echo 1;'), 1000, false);
    }

    public function testmimemismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('x.jpg', '<?php echo 1;'), 1000, false);
    }

    public function testsvgrejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'), 1000, false);
    }

    public function testtoobig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('supera el tamaño máximo');
        UploadValidator::check($this->make('a.pdf', $this->pdf()), 10, false);
    }

    public function testuploaderror(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('a.pdf', $this->pdf(), UPLOAD_ERR_PARTIAL), 1000000, false);
    }

    public function testnotuploadedviahttp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('a.pdf', $this->pdf()), 1000000, true);
    }

    public function testrandombinarydocxrejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('x.docx', random_bytes(300)), 1000000, false);
    }

    public function testrealzipdocxaccepted(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive no disponible.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'upz');
        $this->files[] = $tmp;
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w/>');
        $zip->close();
        $file = ['name' => 'x.docx', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];
        $r = UploadValidator::check($file, 1000000, false);
        $this->assertSame('documento', $r['tipo']);
        $this->assertSame('docx', $r['ext']);
    }

    public function testzipwithoutcontenttypesasdocxrejected(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive no disponible.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'upz');
        $this->files[] = $tmp;
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', 'hola');
        $zip->close();
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check(['name' => 'x.docx', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 1000000, false);
    }

    public function testemptyfile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UploadValidator::check($this->make('a.pdf', ''), 1000000, false);
    }

    /* ---------- CSV ---------- */

    private function assertrejected(string $name, string $content, ?string $message = null, ?array $allowed = null): void
    {
        try {
            UploadValidator::check($this->make($name, $content), 1000000, false, $allowed);
        } catch (\InvalidArgumentException $e) {
            if ($message !== null) $this->assertStringContainsString($message, $e->getMessage());
            else $this->addToAssertionCount(1);
            return;
        }
        $this->fail($name . ' debería rechazarse.');
    }

    public function testcsvsemicolon(): void
    {
        $r = UploadValidator::check($this->make('datos.csv', "nombre;email\nAna;ana@example.test\n"), 1000000, false);
        $this->assertSame('documento', $r['tipo']);
        $this->assertSame('csv', $r['ext']);
        $this->assertNull($r['ancho']);
        $this->assertNull($r['alto']);
    }

    public function testcsvwithbom(): void
    {
        $r = UploadValidator::check($this->make('datos.csv', "\xEF\xBB\xBFnombre;email\r\nAna;ana@example.test\r\n"), 1000000, false);
        $this->assertSame('csv', $r['ext']);
    }

    /** TabularReader lee Windows-1252 si no es UTF-8 válido: así exporta Excel en español. */
    public function testcsvwindows1252(): void
    {
        $r = UploadValidator::check($this->make('datos.csv', "nombre;ciudad\nJos\xE9;Coru\xF1a\n"), 1000000, false);
        $this->assertSame('csv', $r['ext']);
    }

    public function testcsvcommaandtabanduppercase(): void
    {
        $this->assertSame('csv', UploadValidator::check($this->make('a.csv', "a,b\n1,2\n"), 1000000, false)['ext']);
        $this->assertSame('csv', UploadValidator::check($this->make('B.CSV', "a\tb\n1\t2\n"), 1000000, false)['ext']);
    }

    /** Un `<` suelto es un dato normal, sobre todo en datos clínicos. */
    public function testcsvwithlessthanvalueaccepted(): void
    {
        $r = UploadValidator::check($this->make('a.csv', "paciente;hba1c\nP1;<7%\nP2;a<b\n"), 1000000, false);
        $this->assertSame('csv', $r['ext']);
    }

    public function testpngrenamedcsvrejected(): void
    {
        $this->assertrejected('foto.csv', $this->png(), 'no coincide');
    }

    public function testpdfrenamedcsvrejected(): void
    {
        $this->assertrejected('a.csv', $this->pdf(), 'no coincide');
    }

    public function testnulbytecsvrejected(): void
    {
        $this->assertrejected('a.csv', "a;b\n1;\x002\n", 'no coincide');
    }

    /** TabularReader no lee UTF-16 y sus NUL hacen que no se distinga de un binario. */
    public function testutf16csvrejected(): void
    {
        $this->assertrejected('a.csv', "\xFF\xFE" . mb_convert_encoding("a;b\n1;2\n", 'UTF-16LE', 'UTF-8'), 'no coincide');
    }

    public function testphpcontentrenamedcsvrejected(): void
    {
        $this->assertrejected('a.csv', "<?php echo 1;\n", 'no coincide');
    }

    public function testphpafterrowscsvrejected(): void
    {
        $rows = str_repeat("nombre;email\n", 2000);
        $this->assertrejected('a.csv', $rows . "x;<?PHP system('id'); ?>\n", 'no coincide');
        $this->assertrejected('a.csv', $rows . "x;<?= 1 ?>\n", 'no coincide');
    }

    public function testhtmlcontentrenamedcsvrejected(): void
    {
        $this->assertrejected('a.csv', "<!DOCTYPE html><html><body>x</body></html>\n", 'no coincide');
    }

    public function testscriptafterrowscsvrejected(): void
    {
        $this->assertrejected('a.csv', str_repeat("nombre;email\n", 2000) . "x;<SCRIPT src=a></script>\n", 'no coincide');
    }

    public function testdoubleextensionphpcsvwithphpcontentrejected(): void
    {
        $this->assertrejected('x.php.csv', "<?php echo 1;\n", 'no coincide');
    }

    public function testonlybomcsvrejected(): void
    {
        $this->assertrejected('a.csv', "\xEF\xBB\xBF \r\n", 'vacío');
    }

    public function testphpextensionwithtextcontentrejected(): void
    {
        $this->assertrejected('x.php', "a;b\n1;2\n", 'Tipo de fichero no permitido');
    }

    public function testtxtextensionrejected(): void
    {
        $this->assertrejected('x.txt', "a;b\n1;2\n", 'Tipo de fichero no permitido');
    }

    /** Los tipos de texto solo valen para la extensión csv. */
    public function testcsvtextrenamedotherextensionsrejected(): void
    {
        foreach (['x.xls', 'x.xlsx', 'x.pdf', 'x.doc', 'x.zip', 'x.jpg'] as $name) {
            $this->assertrejected($name, "a;b\n1;2\n", 'no coincide');
        }
    }

    /* ---------- extensiones por llamada ---------- */

    public function testallowedextensionaccepted(): void
    {
        $r = UploadValidator::check($this->make('a.csv', "a;b\n1;2\n"), 1000000, false, ['csv', 'xlsx', 'xls']);
        $this->assertSame('csv', $r['ext']);
    }

    public function testallowedlistisnotcasesensitive(): void
    {
        $r = UploadValidator::check($this->make('A.CSV', "a;b\n1;2\n"), 1000000, false, ['CSV']);
        $this->assertSame('csv', $r['ext']);
    }

    public function testextensionoutsideallowedlistrejected(): void
    {
        $this->assertrejected('a.pdf', $this->pdf(), 'Tipo de fichero no permitido', ['csv', 'xlsx', 'xls']);
    }

    public function testnullallowedmeanswholewhitelist(): void
    {
        $r = UploadValidator::check($this->make('a.pdf', $this->pdf()), 1000000, false, null);
        $this->assertSame('pdf', $r['ext']);
    }

    public function testemptyallowedlistrejectseverything(): void
    {
        $this->assertrejected('a.csv', "a;b\n1;2\n", 'Tipo de fichero no permitido', []);
    }

    public function testallowedlistcannotenableextensionsoutsidewhitelist(): void
    {
        $this->assertrejected('x.php', "<?php echo 1;", 'Tipo de fichero no permitido', ['php']);
        $this->assertrejected('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Tipo de fichero no permitido', ['svg']);
        $this->assertrejected('x.txt', "a;b\n", 'Tipo de fichero no permitido', ['txt', 'csv']);
    }

    public function testallowedlistdoesnotskipcontentchecks(): void
    {
        $this->assertrejected('foto.csv', $this->png(), 'no coincide', ['csv']);
    }
}
