<?php declare(strict_types=1);

namespace Tests\Integration\Domain;

use Tests\TestCase;
use App\Domain\AuthDomain;
use App\Domain\Models\User;

class AuthLoginTest extends TestCase
{
    private function storedhash(int $iduser): string
    {
        $stmt = $this->db->prepare('SELECT password FROM users WHERE iduser = ?');
        $stmt->execute([$iduser]);
        return (string) $stmt->fetchColumn();
    }

    public function testLoginWithPasswordHashUser(): void
    {
        $id = $this->fixtureAdminUser('secret123');
        $email = User::get($this->db, $id)['email'];

        $user = AuthDomain::verifycredentials($this->app(), $email, 'secret123');

        $this->assertIsArray($user);
        $this->assertSame($id, (int) $user['iduser']);
        $this->assertArrayNotHasKey('password', $user, 'El hash nunca debe salir del Model');
    }

    public function testWrongPasswordFails(): void
    {
        $id = $this->fixtureAdminUser('secret123');
        $email = User::get($this->db, $id)['email'];

        $this->assertFalse(AuthDomain::verifycredentials($this->app(), $email, 'otra'));
        $this->assertFalse(AuthDomain::verifycredentials($this->app(), 'nadie@test.local', 'secret123'));
    }

    public function testInactiveUserCannotLogin(): void
    {
        $id = $this->fixtureAdminUser('secret123', ['status' => 0]);
        $email = User::get($this->db, $id)['email'];

        $this->assertFalse(AuthDomain::verifycredentials($this->app(), $email, 'secret123'));
    }

    public function testLegacyMd5HashIsRehashedOnSuccessfulLogin(): void
    {
        $email = 'legacy+' . bin2hex(random_bytes(4)) . '@test.local';
        $this->db->prepare("INSERT INTO users (name, surname, email, password, status, created, updated, typeuserid) VALUES ('Legacy','Test',?,?,1,0,0,0)")
            ->execute([$email, md5('vieja')]);
        $id = (int) $this->db->lastInsertId();

        $user = AuthDomain::verifycredentials($this->app(), $email, 'vieja');
        $this->assertIsArray($user);

        $hash = $this->storedhash($id);
        $this->assertFalse(User::islegacyhash($hash), 'Tras el login el md5 debe sustituirse');
        $this->assertTrue(password_verify('vieja', $hash));

        // Y sigue entrando con el hash nuevo.
        $this->assertIsArray(AuthDomain::verifycredentials($this->app(), $email, 'vieja'));
    }

    public function testLegacyMd5WithWrongPasswordIsNotRehashed(): void
    {
        $email = 'legacy+' . bin2hex(random_bytes(4)) . '@test.local';
        $this->db->prepare("INSERT INTO users (name, surname, email, password, status, created, updated, typeuserid) VALUES ('Legacy','Test',?,?,1,0,0,0)")
            ->execute([$email, md5('vieja')]);
        $id = (int) $this->db->lastInsertId();

        $this->assertFalse(AuthDomain::verifycredentials($this->app(), $email, 'mala'));
        $this->assertSame(md5('vieja'), $this->storedhash($id));
    }

    public function testEmptyPasswordUpdateDoesNotOverwrite(): void
    {
        $id = $this->fixtureAdminUser('secret123');
        User::update($this->db, ['iduser' => $id, 'password' => '']);
        $this->assertTrue(password_verify('secret123', $this->storedhash($id)));
    }

    public function testCompleteLoginRecordsAccessAndHidesHash(): void
    {
        $id = $this->fixtureAdminUser('secret123');
        $app = $this->app();
        $user = AuthDomain::verifycredentials($app, User::get($this->db, $id)['email'], 'secret123');
        AuthDomain::completelogin($app, $user);

        $this->assertSame($id, (int) $app->user['iduser']);
        $count = (int) $this->db->query("SELECT COUNT(*) FROM users_logs WHERE action='login' AND userid=" . $id)->fetchColumn();
        $this->assertSame(1, $count);
    }
}
