<?php
namespace Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use App\Domain\AuthDomain;

class AuthDomain2FATest extends TestCase
{
    /** Pasar a email como principal no debe borrar el secreto TOTP: sigue siendo el método de respaldo. */
    public function testSetupKeepsTheStoredSecretWhenSwitchingToEmail()
    {
        $originalschema = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = ['user_2fa' => [
            'userid' => ['type' => 'int', 'primary' => true],
            'primary_method' => ['type' => 'varchar', 'length' => 10, 'required' => true],
            'backup_method' => ['type' => 'varchar', 'length' => 10, 'required' => true],
            'totp_secret' => ['type' => 'varchar', 'length' => 128],
            'backup_email' => ['type' => 'varchar', 'length' => 255],
            'configured_at' => ['type' => 'int', 'required' => true],
        ]];
        $db = new class {
            public array $updated = [];
            public function runone($sql, $bind = []) { return ['userid' => '7', 'totp_secret' => 'SECRETO']; }
            public function run($sql, $bind = []) { return 1; }
            public function update($table, $info, $where, $bind = '') { $this->updated[] = $info; return 1; }
        };
        try {
            AuthDomain::setup2FA($db, 7, 'email', 'totp', null, null);
        } finally {
            $GLOBALS['dbschema'] = $originalschema;
        }
        $this->assertCount(1, $db->updated);
        $this->assertSame('email', $db->updated[0]['primary_method']);
        $this->assertArrayNotHasKey('totp_secret', $db->updated[0]);
        $this->assertArrayNotHasKey('backup_email', $db->updated[0]);
    }

    public function testGenerateTOTPSecret()
    {
        $secret = AuthDomain::generateTOTPSecret();

        $this->assertIsString($secret);
        $this->assertGreaterThan(0, strlen($secret));
        // Base32 encoded (should match pattern)
        $this->assertMatchesRegularExpression('/^[A-Z2-7=]+$/', $secret);
    }

    public function testValidateTOTPWithValidCode()
    {
        $secret = AuthDomain::generateTOTPSecret();

        // Generate a valid code for the current time window
        $totp = \OTPHP\TOTP::create($secret);
        $code = $totp->now();

        // Validate
        $valid = AuthDomain::validateTOTP($secret, $code);
        $this->assertTrue($valid);
    }

    public function testValidateTOTPWithInvalidCode()
    {
        $secret = AuthDomain::generateTOTPSecret();

        // Invalid code
        $valid = AuthDomain::validateTOTP($secret, '000000');
        $this->assertFalse($valid);
    }

    public function testGenerateQRCode()
    {
        $secret = AuthDomain::generateTOTPSecret();
        $email = 'user@example.com';

        $qr = AuthDomain::generateQRCode($secret, $email);

        // Un QR escaneable de verdad (SVG con módulos), no un SVG con el texto de la URI.
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $qr);
        $svg = (string) base64_decode(substr($qr, strlen('data:image/svg+xml;base64,')), true);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertMatchesRegularExpression('/<(path|rect)\b/', $svg);
        $this->assertStringNotContainsString('otpauth://', $svg, 'el secreto no debe ir como texto legible');
    }

    public function testValidateEmailCodeWithLockout()
    {
        // El bloqueo por intentos se prueba en tests/Integration/Domain/AuthDomain2FATest.php
        // For now, just ensure the methods exist
        $this->assertTrue(method_exists(AuthDomain::class, 'send2FAEmail'));
        $this->assertTrue(method_exists(AuthDomain::class, 'validateEmailCode'));
    }
}
