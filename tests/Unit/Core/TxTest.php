<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Tx;
use PHPUnit\Framework\TestCase;

final class TxTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE t (v INTEGER)');
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn();
    }

    public function testCommitEnExito(): void
    {
        $r = Tx::run($this->pdo, function () {
            $this->pdo->exec('INSERT INTO t VALUES (1)');
            return 'ok';
        });
        $this->assertSame('ok', $r);
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->rows());
    }

    public function testRollbackYRelanzaEnFallo(): void
    {
        try {
            Tx::run($this->pdo, function () {
                $this->pdo->exec('INSERT INTO t VALUES (1)');
                throw new \InvalidArgumentException('mal');
            });
            $this->fail('Debía relanzar');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('mal', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->rows());
    }

    public function testAnidadoNoAbreOtraTransaccion(): void
    {
        Tx::run($this->pdo, function () {
            $this->pdo->exec('INSERT INTO t VALUES (1)');
            Tx::run($this->pdo, function () {
                $this->assertTrue($this->pdo->inTransaction());
                $this->pdo->exec('INSERT INTO t VALUES (2)');
            });
            $this->assertTrue($this->pdo->inTransaction(), 'el interno no debe hacer commit');
        });
        $this->assertSame(2, $this->rows());
    }

    public function testSeUneAUnaTransaccionAbiertaSinConfirmarla(): void
    {
        $this->pdo->beginTransaction();
        Tx::run($this->pdo, fn() => $this->pdo->exec('INSERT INTO t VALUES (1)'));
        $this->assertTrue($this->pdo->inTransaction(), 'la transacción sigue siendo de quien la abrió');
        $this->pdo->rollBack();
        $this->assertSame(0, $this->rows());
    }

    public function testFalloAnidadoDeshaceTodoElExterno(): void
    {
        try {
            Tx::run($this->pdo, function () {
                $this->pdo->exec('INSERT INTO t VALUES (1)');
                Tx::run($this->pdo, function () {
                    throw new \RuntimeException('interno');
                });
            });
            $this->fail('Debía relanzar');
        } catch (\RuntimeException $e) {
            $this->assertSame('interno', $e->getMessage());
        }
        $this->assertSame(0, $this->rows());
    }
}
