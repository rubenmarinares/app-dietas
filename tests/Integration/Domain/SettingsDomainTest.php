<?php declare(strict_types=1);

namespace Tests\Integration\Domain;

use Tests\TestCase;
use App\Domain\SettingsDomain;
use App\Domain\AuthDomain;
use App\Domain\Models\UserLog;

class SettingsDomainTest extends TestCase
{
    public function testUsersListHasLastaccessAndNoPasswordHash(): void
    {
        $userid = $this->fixtureAdminUser();
        UserLog::record($this->db, $userid, 'login', '20260428');
        UserLog::record($this->db, $userid, 'login', '20260415');

        $users = SettingsDomain::index($this->app())["users"];
        $user = current(array_filter($users, fn($u) => (int)$u['iduser'] === $userid));

        $this->assertNotFalse($user);
        $this->assertSame(20260428, $user['lastaccess'], 'MAX(stamp) de users_logs');
        $this->assertArrayNotHasKey('password', $user);
    }

    public function testNewUserGetsGraceDeadlineAndCanLogin(): void
    {
        $email = 'nuevo+' . bin2hex(random_bytes(4)) . '@test.local';
        SettingsDomain::user_save($this->app(), [
            'iduser' => 0,
            'name' => 'Nuevo',
            'surname' => 'Usuario',
            'email' => $email,
            'newpassword' => 'clave-nueva',
            'status' => 1,
            'typeuserid' => 1,
        ]);

        $user = AuthDomain::verifycredentials($this->app(), $email, 'clave-nueva');
        $this->assertIsArray($user);
        $this->assertTrue(AuthDomain::isInGracePeriod($this->db, (int) $user['iduser']));
    }

    public function testEditWithoutNewPasswordKeepsPassword(): void
    {
        $userid = $this->fixtureAdminUser('original');
        $email = SettingsDomain::user($this->app(), $userid)['email'];

        SettingsDomain::user_save($this->app(), ['iduser' => $userid, 'name' => 'Renombrado', 'newpassword' => '']);

        $this->assertIsArray(AuthDomain::verifycredentials($this->app(), $email, 'original'));
        $this->assertSame('Renombrado', SettingsDomain::user($this->app(), $userid)['name']);
    }

    public function testUserDeleteRemovesTheRightRow(): void
    {
        $keep = $this->fixtureAdminUser();
        $gone = $this->fixtureAdminUser();

        SettingsDomain::user_delete($this->app(), $gone);

        $this->assertEmpty(SettingsDomain::user($this->app(), $gone));
        $this->assertNotEmpty(SettingsDomain::user($this->app(), $keep));
    }
}
