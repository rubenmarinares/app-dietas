<?php declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Models\User;
use App\Domain\SettingsDomain;
use App\Domain\UserDomain;
use PHPUnit\Framework\TestCase;

/** Reglas de las cuentas del panel: campos editables, contraseña nueva y contraseña actual. */
final class AccountRulesTest extends TestCase
{
    /** Solo los campos del formulario: ni deadline2fa (saltarse el 2FA) ni password en claro ni columnas inventadas. */
    public function testuserfieldswhitelist(): void
    {
        $post = ['iduser' => '9', 'name' => 'Ana', 'surname' => 'Pérez', 'email' => 'a@x.es', 'typeuserid' => '2', 'status' => '1',
            'newpassword' => 'nueva-clave-larga', 'password' => 'otra', 'deadline2fa' => '29991231', 'csrf_token' => 't', 'eliminar' => '1'];
        $this->assertSame(['iduser' => 9, 'name' => 'Ana', 'surname' => 'Pérez', 'email' => 'a@x.es', 'typeuserid' => 2, 'status' => 1,
            'password' => 'nueva-clave-larga'], SettingsDomain::userfields($post));
    }

    public function testuserfieldswithoutnewpasswordkeepsthecurrentone(): void
    {
        $out = SettingsDomain::userfields(['iduser' => '0', 'name' => 'Ana', 'newpassword' => '']);
        $this->assertArrayNotHasKey('password', $out);
        $this->assertSame(0, $out['iduser']);
    }

    /** @dataProvider passwords */
    public function testpasswordissue(string $new, string $repeat, ?string $expected): void
    {
        $this->assertSame($expected, UserDomain::passwordissue($new, $repeat));
    }

    public static function passwords(): array
    {
        return [
            'válida' => ['una-clave-larga', 'una-clave-larga', null],
            'vacía' => ['', '', 'La contraseña nueva no puede estar vacía.'],
            'corta' => ['corta', 'corta', 'La contraseña nueva debe tener al menos 10 caracteres.'],
            'distintas' => ['una-clave-larga', 'otra-clave-larga', 'Las dos contraseñas nuevas no coinciden.'],
        ];
    }

    public function testpasswordmatchesmodernandlegacyhashes(): void
    {
        $this->assertTrue(UserDomain::passwordmatches(User::encrypt('secreto-actual'), 'secreto-actual'));
        $this->assertFalse(UserDomain::passwordmatches(User::encrypt('secreto-actual'), 'otra'));
        $this->assertTrue(UserDomain::passwordmatches(md5('heredada'), 'heredada'));
        $this->assertFalse(UserDomain::passwordmatches(null, ''));
        $this->assertFalse(UserDomain::passwordmatches('', ''));
    }
}
