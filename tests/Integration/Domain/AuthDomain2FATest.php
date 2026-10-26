<?php declare(strict_types=1);

namespace Tests\Integration\Domain;

use Tests\TestCase;
use App\Domain\AuthDomain;

class AuthDomain2FATest extends TestCase
{
    public function testUserInGracePeriod(): void
    {
        $userid = $this->fixtureAdminUser();
        $this->assertTrue(AuthDomain::isInGracePeriod($this->db, $userid));
    }

    public function testUserWithoutDeadlineIsNotInGracePeriod(): void
    {
        $userid = $this->fixtureAdminUser('x', ['deadline2fa' => null]);
        $this->assertFalse(AuthDomain::isInGracePeriod($this->db, $userid));
    }

    public function testSetupTOTPClearsDeadlineAndValidatesCode(): void
    {
        $userid = $this->fixtureAdminUser();
        $secret = AuthDomain::generateTOTPSecret();

        AuthDomain::setup2FA($this->db, $userid, 'totp', 'email', $secret, 'test@test.local');

        $this->assertTrue(AuthDomain::hasConfigured2FA($this->db, $userid));
        $this->assertNull(AuthDomain::getDeadline2FA($this->db, $userid));

        $code = (string) \OTPHP\TOTP::create($secret)->now();
        $this->assertTrue(AuthDomain::validate2FA($this->db, $userid, $code, 'totp'));
        $this->assertFalse(AuthDomain::validate2FA($this->db, $userid, '000000', 'totp') && $code !== '000000');
    }

    public function testSetupTwiceUpdatesInsteadOfFailing(): void
    {
        $userid = $this->fixtureAdminUser();
        AuthDomain::setup2FA($this->db, $userid, 'totp', 'email', AuthDomain::generateTOTPSecret(), 'test@test.local');
        AuthDomain::setup2FA($this->db, $userid, 'email', 'totp', null, 'test@test.local');

        $data = AuthDomain::pendinguser($this->db, $userid);
        $this->assertSame('email', $data['twofa']['primary_method']);
    }

    public function testResetGivesNewGraceDeadline(): void
    {
        $userid = $this->fixtureAdminUser();
        AuthDomain::setup2FA($this->db, $userid, 'totp', 'email', AuthDomain::generateTOTPSecret(), 'test@test.local');

        AuthDomain::reset2FA($this->db, $userid);

        $this->assertFalse(AuthDomain::hasConfigured2FA($this->db, $userid));
        $this->assertSame(AuthDomain::gracedeadline(), (int) AuthDomain::getDeadline2FA($this->db, $userid));
    }

    public function testEmailCodeIsSingleUseAndLocksAfterThreeAttempts(): void
    {
        $userid = $this->fixtureAdminUser();
        // Código insertado a mano para no depender de SMTP.
        $this->db->prepare('INSERT INTO user_2fa_emails (userid, code, created_at, attempts) VALUES (?, ?, ?, 0)')
            ->execute([$userid, '123456', time()]);

        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse(AuthDomain::validateEmailCode($this->db, $userid, '000000'));
        }
        $this->expectException(\Exception::class);
        AuthDomain::validateEmailCode($this->db, $userid, '123456');
    }

    public function testValidEmailCodeIsConsumed(): void
    {
        $userid = $this->fixtureAdminUser();
        $this->db->prepare('INSERT INTO user_2fa_emails (userid, code, created_at, attempts) VALUES (?, ?, ?, 0)')
            ->execute([$userid, '654321', time()]);

        $this->assertTrue(AuthDomain::validateEmailCode($this->db, $userid, '654321'));
        $this->assertFalse(AuthDomain::validateEmailCode($this->db, $userid, '654321'));
    }
}
