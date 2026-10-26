<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use App\Core\Database;
use App\Domain\Models\User;

/**
 * Base de los tests de INTEGRACIÓN (BBDD real, sin mocks de PDO).
 * Si la BBDD de test no está disponible, el test se marca como skipped.
 * Los usuarios de prueba usan emails *@test.local y se purgan en cada setUp.
 */
abstract class TestCase extends BaseTestCase
{
    protected \PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (!testdbavailable()) {
            $this->markTestSkipped('BBDD de test no disponible (configura tests/.env.local y ejecuta php migrations/apply.php --test).');
        }
        $this->db = Database::getInstance();
        $this->resettestusers();
    }

    /** Objeto mínimo con ->db para métodos de Domain que reciben $app. */
    protected function app(): object
    {
        return new class($this->db) {
            public $db;
            public $user = null;
            public $session;
            public function __construct($db) { $this->db = $db; $this->session = new \App\Core\Session(); }
            public function setUser($item) { unset($item['password']); $this->user = $item; }
        };
    }

    /** Borra usuarios de prueba (*@test.local) y sus datos 2FA/logs. */
    protected function resettestusers(): void
    {
        $this->db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['user_2fa', 'user_2fa_emails', 'users_logs'] as $t) {
            try {
                $this->db->exec("DELETE FROM `$t` WHERE userid IN (SELECT iduser FROM users WHERE email LIKE '%@test.local')");
            } catch (\PDOException $e) { /* tabla aún no creada */ }
        }
        try { $this->db->exec("DELETE FROM users WHERE email LIKE '%@test.local'"); } catch (\PDOException $e) {}
        $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Usuario activo que puede hacer login con $password (hash actual, password_hash)
     * y con plazo de gracia 2FA vigente. Devuelve iduser.
     */
    protected function fixtureAdminUser(string $password = 'x', array $override = []): int
    {
        $data = array_merge([
            'name' => 'Admin',
            'surname' => 'Test',
            'email' => 'admin+' . bin2hex(random_bytes(4)) . '@test.local',
            'password' => $password,
            'status' => 1,
            'typeuserid' => 0,
            'deadline2fa' => (int) date('Ymd', strtotime('+7 days')),
        ], $override);
        // Por el Model: así el hash es el mismo que usará la app.
        return (int) User::insert($this->db, $data);
    }
}
