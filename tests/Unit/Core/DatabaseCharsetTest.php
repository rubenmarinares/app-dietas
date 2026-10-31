<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database;
use App\Core\db;
use PHPUnit\Framework\TestCase;

/**
 * La conexión va en utf8mb4: con `SET NAMES utf8` (utf8mb3) los caracteres de 4 bytes (emojis, algunos símbolos)
 * se pierden o fallan al escribir. Tampoco `SET CHARACTER SET`, que pone la conexión en el juego de caracteres
 * de la BBDD.
 */
final class DatabaseCharsetTest extends TestCase
{
    public function testinitcommandisutf8mb4(): void
    {
        class_exists(Database::class); // `db` vive en Database.php
        $this->assertSame('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci', db::INITCOMMAND);
        $this->assertSame(db::INITCOMMAND, db::options()[\PDO::MYSQL_ATTR_INIT_COMMAND]);
    }

    public function testdsnsareutf8mb4(): void
    {
        foreach (['src/Config/constants.php', 'tests/bootstrap.php', 'migrations/apply.php'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../../../' . $file);
            $this->assertStringContainsString(";charset=utf8mb4'", $src, $file);
            $this->assertStringNotContainsString(';charset=utf8\'', $src, $file);
        }
    }
}
