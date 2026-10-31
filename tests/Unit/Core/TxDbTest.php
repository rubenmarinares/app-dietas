<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database;
use App\Core\db;
use App\Core\Tx;
use PHPUnit\Framework\TestCase;

/** Tx con el `db` del proyecto (run/insert/delete reales) sobre SQLite en memoria: un fallo de escritura sin comprobar deshace todo. */
final class TxDbTest extends TestCase
{
    private db $db;
    private $previouslog;
    private string $logfile;

    protected function setUp(): void
    {
        class_exists(Database::class); // `db` vive en Database.php
        // Mismo `db` con SQLite: se salta solo el constructor (sus opciones son de MySQL).
        $this->db = new class('sqlite::memory:') extends db {
            public function __construct(string $dsn) { \PDO::__construct($dsn, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]); }
        };
        $this->db->exec('CREATE TABLE t (v INTEGER UNIQUE)');
        $this->logfile = (string) tempnam(sys_get_temp_dir(), 'txdb');
        $this->previouslog = ini_set('error_log', $this->logfile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previouslog === false ? '' : (string) $this->previouslog);
        @unlink($this->logfile);
    }

    private function rows(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM t')->fetchColumn();
    }

    public function testFueraDeTxUnErrorDevuelveFalse(): void
    {
        $this->assertFalse($this->db->throwonerror);
        $this->assertTrue($this->db->insert('t', ['v' => 1]));
        $this->assertFalse($this->db->insert('t', ['v' => 1]));
        $this->assertFalse($this->db->run('UPDATE nope SET v = 1'));
        $this->assertFalse($this->db->runone('UPDATE nope SET v = 1'));
    }

    public function testRunRelanzaConThrowonerror(): void
    {
        $this->db->throwonerror = true;
        $this->expectException(\PDOException::class);
        $this->db->run('UPDATE nope SET v = 1');
    }

    public function testRunoneRelanzaConThrowonerror(): void
    {
        $this->db->throwonerror = true;
        $this->expectException(\PDOException::class);
        $this->db->runone('UPDATE nope SET v = 1');
    }

    public function testEscrituraFallidaSinComprobarDeshaceLaTransaccion(): void
    {
        try {
            Tx::run($this->db, function () {
                $this->db->insert('t', ['v' => 1]);
                $this->db->insert('t', ['v' => 1]); // duplicado: el llamador no mira el resultado
                $this->db->insert('t', ['v' => 2]);
            });
            $this->fail('Debía lanzar');
        } catch (\PDOException $e) {
        }
        $this->assertSame(0, $this->rows());
        $this->assertFalse($this->db->inTransaction());
        $this->assertFalse($this->db->throwonerror, 'se restaura el modo anterior');
    }

    public function testDeleteFallidoTambienDeshace(): void
    {
        $this->expectException(\PDOException::class);
        try {
            Tx::run($this->db, function () {
                $this->db->insert('t', ['v' => 5]);
                $this->db->delete('nope', 'v = ?', [5]);
            });
        } finally {
            $this->assertSame(0, $this->rows());
        }
    }

    public function testThrowonerrorActivoDuranteElBloqueYRestauradoAlAcabar(): void
    {
        Tx::run($this->db, fn() => $this->assertTrue($this->db->throwonerror));
        $this->assertFalse($this->db->throwonerror);
    }

    public function testThrowonerrorRestauraUnValorPrevioActivoTrasExcepcion(): void
    {
        $this->db->throwonerror = true;
        try {
            Tx::run($this->db, function () {
                throw new \RuntimeException('mal');
            });
            $this->fail('Debía relanzar');
        } catch (\RuntimeException $e) {
        }
        $this->assertTrue($this->db->throwonerror);
    }

    public function testAnidadoRestauraElModoDelExterno(): void
    {
        Tx::run($this->db, function () {
            Tx::run($this->db, fn() => $this->db->insert('t', ['v' => 1]));
            $this->assertTrue($this->db->throwonerror, 'el interno no desactiva el modo del externo');
        });
        $this->assertFalse($this->db->throwonerror);
        $this->assertSame(1, $this->rows());
    }
}
