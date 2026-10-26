<?php declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reglas del asistente de 2FA verificadas estáticamente:
 *  - El secreto TOTP no viaja por el formulario: se guarda en sesión entre pasos.
 *  - El campo CSRF se llama csrf_token, como en el resto de formularios.
 */
class TwoFactorSetupTest extends TestCase
{
    private const SCREEN = __DIR__ . '/../../src/Screens/UserProfileScreen.php';
    private const VIEWS  = __DIR__ . '/../../src/Screens/Views/profile';

    public function testTotpSecretIsNotSentAsHiddenField(): void
    {
        $tpl = file_get_contents(self::VIEWS . '/setup_2fa_totp.html');
        $this->assertDoesNotMatchRegularExpression('/name="secret"/', $tpl,
            'El secreto TOTP no debe ir en un campo del formulario.');
    }

    public function testScreenDoesNotReadSecretFromPost(): void
    {
        $src = file_get_contents(self::SCREEN);
        $this->assertDoesNotMatchRegularExpression('/\$_POST\[[\'"]secret[\'"]\]/', $src,
            'UserProfileScreen no debe leer el secreto TOTP de $_POST.');
    }

    public function testCsrfFieldIsCsrfToken(): void
    {
        $violations = [];
        foreach (array_merge([self::SCREEN], glob(self::VIEWS . '/*.html')) as $file) {
            if (str_contains(file_get_contents($file), '_csrf"') || str_contains(file_get_contents($file), "_csrf'")) {
                $violations[] = basename($file);
            }
        }
        $this->assertSame([], $violations, 'Usar csrf_token, no _csrf: ' . implode(', ', $violations));
    }
}
