<?php
namespace Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use App\Domain\AuthDomain;

class AuthDomain2FATest extends TestCase
{
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
