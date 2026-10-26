<?php
namespace Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use App\Domain\LoginThrottle;

class LoginThrottleTest extends TestCase
{
    private function fails(int $n, string $email, string $ip, int $stamp): array
    {
        return array_fill(0, $n, ['email' => $email, 'ip' => $ip, 'stamp' => $stamp]);
    }

    public function testCuatroFallosNoBloquean(): void
    {
        $this->assertFalse(LoginThrottle::isblocked($this->fails(4, 'a@x.es', '1.1.1.1', 1000), 1100, 'a@x.es', '1.1.1.1'));
    }

    public function testCincoFallosPorEmailBloquean(): void
    {
        $this->assertTrue(LoginThrottle::isblocked($this->fails(5, 'a@x.es', '1.1.1.1', 1000), 1100, 'A@x.es ', '9.9.9.9'));
    }

    public function testVeinteFallosPorIpBloquean(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) $rows[] = ['email' => "u$i@x.es", 'ip' => '2.2.2.2', 'stamp' => 1000];
        $this->assertTrue(LoginThrottle::isblocked($rows, 1100, 'otro@x.es', '2.2.2.2'));
        $this->assertFalse(LoginThrottle::isblocked(array_slice($rows, 0, 19), 1100, 'otro@x.es', '2.2.2.2'));
    }

    public function testFallosAntiguosNoCuentan(): void
    {
        $rows = $this->fails(5, 'a@x.es', '1.1.1.1', 1000);
        $this->assertTrue(LoginThrottle::isblocked($rows, 1000 + 899, 'a@x.es', '1.1.1.1'));
        $this->assertFalse(LoginThrottle::isblocked($rows, 1000 + 900, 'a@x.es', '1.1.1.1'));
    }

    public function testClaveDe2faPorUsuario(): void
    {
        $this->assertSame('2fa:42', LoginThrottle::twofakey(42));
    }

    public function testCincoFallosDe2faBloqueanSoloEseUsuario(): void
    {
        $rows = $this->fails(5, LoginThrottle::twofakey(42), '1.1.1.1', 1000);
        $this->assertTrue(LoginThrottle::isblocked($rows, 1100, LoginThrottle::twofakey(42)));
        $this->assertFalse(LoginThrottle::isblocked($rows, 1100, LoginThrottle::twofakey(43)));
        // Los fallos de 2FA no bloquean el email de la contraseña (son claves distintas).
        $this->assertFalse(LoginThrottle::isblocked($rows, 1100, 'a@x.es'));
    }

    public function testOtroEmailNoSeBloquea(): void
    {
        $this->assertFalse(LoginThrottle::isblocked($this->fails(5, 'a@x.es', '1.1.1.1', 1000), 1100, 'b@x.es', '3.3.3.3'));
    }
}
