<?php declare(strict_types=1);

namespace Tests\Integration\Domain;

use App\Domain\PermissionDomain;
use App\Domain\SettingsDomain;

class PermissionDomainDbTest extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->exec("DELETE FROM typesuser WHERE typeuser LIKE 'TEST\\_%'");
    }

    private function fixturetypeuser(?string $permissionsjson): int
    {
        $stmt = $this->db->prepare('INSERT INTO typesuser (typeuser, permissions) VALUES (?, ?)');
        $stmt->execute(['TEST_' . bin2hex(random_bytes(4)), $permissionsjson]);
        return (int)$this->db->lastInsertId();
    }

    public function testreadonlytypecanreadbutnotwrite(): void
    {
        $typeid = $this->fixturetypeuser('{"*": "read"}');
        $perms = PermissionDomain::permissionsforuser($this->db, ['iduser' => 1, 'typeuserid' => $typeid]);

        $this->assertSame(['*' => 'read'], $perms);
        $this->assertTrue(PermissionDomain::can($perms, 'settings', PermissionDomain::requiredlevelformethod('GET')));
        $this->assertFalse(PermissionDomain::can($perms, 'settings', PermissionDomain::requiredlevelformethod('POST')));
    }

    public function testnullpermissionskeepsfullaccess(): void
    {
        $typeid = $this->fixturetypeuser(null);
        $perms = PermissionDomain::permissionsforuser($this->db, ['typeuserid' => $typeid]);

        $this->assertNull($perms);
        $this->assertTrue(PermissionDomain::can($perms, 'settings', 'write'));
    }

    public function testusertypezeroordeletedmeansfullaccess(): void
    {
        $this->assertNull(PermissionDomain::permissionsforuser($this->db, ['typeuserid' => 0]));
        $this->assertNull(PermissionDomain::permissionsforuser($this->db, ['typeuserid' => 999999999]));
    }

    public function testnonobjectjsonfailsclosed(): void
    {
        // JSON válido que no es objeto (un string suelto) => sin acceso.
        $typeid = $this->fixturetypeuser('"cadena"');
        $perms = PermissionDomain::permissionsforuser($this->db, ['typeuserid' => $typeid]);

        $this->assertSame([], $perms);
        $this->assertFalse(PermissionDomain::can($perms, 'settings', 'read'));
    }

    public function testseededadministratorhasfullwriteaccess(): void
    {
        // 000_base_schema.sql siembra idtypeuser=1 "Administrador" con {"*":"write"}.
        $perms = PermissionDomain::permissionsforuser($this->db, ['typeuserid' => 1]);
        $this->assertTrue(PermissionDomain::can($perms, 'settings', 'write'));
    }

    public function testtypeusersaveroundtripandclear(): void
    {
        SettingsDomain::typeuser_save($this->app(), [
            'idtypeuser' => 0,
            'typeuser' => 'TEST_lectura',
            'permissions' => '{"*": "read"}',
        ]);
        $row = $this->db->query("SELECT * FROM typesuser WHERE typeuser = 'TEST_lectura'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(['*' => 'read'], json_decode($row['permissions'], true));

        // Vaciar el textarea vuelve a NULL (acceso total).
        SettingsDomain::typeuser_save($this->app(), [
            'idtypeuser' => (int) $row['idtypeuser'],
            'typeuser' => 'TEST_lectura',
            'permissions' => '',
        ]);
        $row = $this->db->query("SELECT * FROM typesuser WHERE idtypeuser = " . (int) $row['idtypeuser'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['permissions']);
    }

    public function testtypeusersaverejectsinvalidjson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingsDomain::typeuser_save($this->app(), [
            'idtypeuser' => 0,
            'typeuser' => 'TEST_bad',
            'permissions' => '{invalido',
        ]);
    }
}
