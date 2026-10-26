<?php
namespace Tests\Integration\Domain;

use Tests\TestCase;
use App\Domain\AuthDomain;

class LoginThrottleDbTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec("DELETE FROM login_intentos WHERE email LIKE '%@test.local' OR email LIKE '2fa:%'");
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        $this->db->exec("DELETE FROM login_intentos WHERE email LIKE '%@test.local' OR email LIKE '2fa:%'");
        parent::tearDown();
    }

    public function testTotpSeBloqueaTrasCincoFallosAunqueSeRepitaLaContrasena(): void
    {
        $id = $this->fixtureAdminUser('secreto');
        $email = $this->db->runone('SELECT email FROM users WHERE iduser = ?', [$id])['email'];
        $secret = AuthDomain::generateTOTPSecret();
        AuthDomain::setup2FA($this->db, $id, 'totp', 'email', $secret, 'test@test.local');
        $code = (string) \OTPHP\TOTP::create($secret)->now();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) $this->assertFalse(AuthDomain::verify2fa($this->db, $id, $wrong, 'totp', '203.0.113.7'));
        // Volver a pasar la fase de contraseña no reinicia el contador del segundo factor.
        $this->assertNotFalse(AuthDomain::verifycredentials($this->app(), $email, 'secreto'));
        $this->expectException(\App\Domain\LoginThrottledException::class);
        AuthDomain::verify2fa($this->db, $id, $code, 'totp', '203.0.113.7');
    }

    public function testTotpCorrectoLimpiaLosFallos(): void
    {
        $id = $this->fixtureAdminUser('secreto');
        $secret = AuthDomain::generateTOTPSecret();
        AuthDomain::setup2FA($this->db, $id, 'totp', 'email', $secret, 'test@test.local');
        $code = (string) \OTPHP\TOTP::create($secret)->now();
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 4; $i++) AuthDomain::verify2fa($this->db, $id, $wrong, 'totp', '203.0.113.7');
        $this->assertTrue(AuthDomain::verify2fa($this->db, $id, $code, 'totp', '203.0.113.7'));
        $this->assertSame(0, (int) $this->db->runone("SELECT COUNT(*) n FROM login_intentos WHERE email = ?", ['2fa:' . $id])['n']);
    }

    public function testBloqueaTrasCincoFallosYUnExitoLimpia(): void
    {
        $id = $this->fixtureAdminUser('secreto');
        $email = $this->db->runone('SELECT email FROM users WHERE iduser = ?', [$id])['email'];
        $app = $this->app();
        for ($i = 0; $i < 4; $i++) $this->assertFalse(AuthDomain::verifycredentials($app, $email, 'mal'));
        $this->assertNotFalse(AuthDomain::verifycredentials($app, $email, 'secreto'));
        // El éxito borró los fallos: otros 5 fallos vuelven a hacer falta para bloquear.
        for ($i = 0; $i < 5; $i++) $this->assertFalse(AuthDomain::verifycredentials($app, $email, 'mal'));
        $this->expectException(\App\Domain\LoginThrottledException::class);
        AuthDomain::verifycredentials($app, $email, 'secreto');
    }
}
