<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/**
 * Los mensajes de PDO y PHPMailer llevan direcciones de email: no deben llegar enteros a error_log.
 * Comprobación sobre el código fuente: estos caminos de error no se pueden provocar sin BBDD ni SMTP.
 */
final class LogMaskingTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function sitesprovider(): array
    {
        return [
            'insert de Database' => ['Database.php', "error_log('[DB] insert fallido en '.\$table.': '.Pii::maskmessage((string)\$this->error));"],
            'catch de Database'  => ['Database.php', "error_log('[DB] '.Pii::maskmessage(\$e->getMessage()).' | '.\$this->sql);"],
            'Mailer'             => ['Mailer.php', "error_log('Mailer failed: ' . Pii::maskmessage(\$e->getMessage()));"],
            'App'                => ['App.php', "error_log('[App] '.get_class(\$e).': '.Pii::maskmessage(\$e->getMessage()).' en '.\$e->getFile().':'.\$e->getLine());"],
        ];
    }

    /** @dataProvider sitesprovider */
    public function testsitemasks(string $file, string $expected): void
    {
        $this->assertStringContainsString($expected, $this->source($file));
    }

    /** runone y run: los dos catch, no solo uno. */
    public function testdatabasemasksbothcatchblocks(): void
    {
        $this->assertSame(2, substr_count($this->source('Database.php'), "'[DB] '.Pii::maskmessage(\$e->getMessage())"));
    }

    /** Ningún error_log de estos ficheros lleva el mensaje en crudo, salvo el fallo de conexión (sin datos de usuario). */
    public function testnorawmessagesleft(): void
    {
        foreach (['Database.php', 'Mailer.php', 'App.php'] as $file) {
            foreach (explode("\n", $this->source($file)) as $line) {
                if (!str_contains($line, 'error_log(') || str_contains($line, 'conexión fallida')) continue;
                $raw = preg_replace('/Pii::maskmessage\((\(string\))?\$(e->getMessage\(\)|this->error)\)/', '', $line);
                $this->assertDoesNotMatchRegularExpression('/getMessage\(\)|\$this->error/', (string) $raw, $file . ': ' . trim($line));
            }
        }
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/src/Core/' . $file);
    }
}
