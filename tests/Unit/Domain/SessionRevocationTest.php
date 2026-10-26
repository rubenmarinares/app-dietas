<?php declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\AuthDomain;
use PHPUnit\Framework\TestCase;

/**
 * Revocación de sesiones del panel: la sesión guarda una huella de la contraseña al iniciar sesión y en cada
 * petición se compara con la fila actual del usuario. Desactivarlo, borrarlo o cambiarle la contraseña cierra
 * todas sus sesiones abiertas.
 */
final class SessionRevocationTest extends TestCase
{
    private const HASH = '$2y$10$abcdefghijklmnopqrstuuJ8p0K1u1q8y9n2m3l4k5j6h7g8f9e0d';

    public function testfingerprintisstableandnotthehash(): void
    {
        $fp = AuthDomain::fingerprint(self::HASH);
        $this->assertSame($fp, AuthDomain::fingerprint(self::HASH));
        $this->assertNotSame(self::HASH, $fp);
        $this->assertStringNotContainsString(self::HASH, $fp);
        $this->assertNotSame($fp, AuthDomain::fingerprint(self::HASH . 'x'));
    }

    public function testactiveuserwithsamepasswordkeepsthesession(): void
    {
        $row = ['iduser' => 7, 'status' => 1, 'password' => self::HASH];
        $this->assertTrue(AuthDomain::sessionvalid($row, AuthDomain::fingerprint(self::HASH)));
    }

    /** @dataProvider revoked */
    public function testsessionisrevoked(?array $row, ?string $fp): void
    {
        $this->assertFalse(AuthDomain::sessionvalid($row, $fp));
    }

    public static function revoked(): array
    {
        $fp = AuthDomain::fingerprint(self::HASH);
        return [
            'usuario borrado' => [null, $fp],
            'usuario desactivado' => [['iduser' => 7, 'status' => 0, 'password' => self::HASH], $fp],
            'contraseña cambiada' => [['iduser' => 7, 'status' => 1, 'password' => self::HASH . 'x'], $fp],
            'sesión antigua sin huella' => [['iduser' => 7, 'status' => 1, 'password' => self::HASH], null],
            'sin contraseña' => [['iduser' => 7, 'status' => 1, 'password' => ''], AuthDomain::fingerprint('')],
        ];
    }
}
