<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ModelBase;
use PHPUnit\Framework\TestCase;

final class ShortJoinModel extends ModelBase
{
    static public $tablename = 'users';
    // Formato corto documentado en Models/CLAUDE.md: alias, tabla origen y condición extra son opcionales.
    static public $joins = [["typesuser", "typeuserid", "idtypeuser", ["typeuser"]]];
}

final class FullJoinModel extends ModelBase
{
    static public $tablename = 'users';
    static public $joins = [["typesuser", "typeuserid", "idtypeuser", ["typeuser"], "t", "users", "AND t.idtypeuser>0"]];
}

final class ModelBaseJoinsTest extends TestCase
{
    private $originalschema;

    protected function setUp(): void
    {
        $this->originalschema = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = ['users' => ['iduser' => ['type' => 'int', 'primary' => true]]];
        // Un "Undefined array key" debe hacer fallar el test, no solo ensuciar la salida.
        set_error_handler(function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $GLOBALS['dbschema'] = $this->originalschema;
    }

    public function testshortjoinbuildssqlwithoutwarnings(): void
    {
        $this->assertSame(' FROM users  LEFT JOIN typesuser  ON (users.typeuserid=typesuser.idtypeuser ) ', ShortJoinModel::_sqljoins());
        $this->assertStringContainsString(' , typesuser.typeuser ', ShortJoinModel::_sqlselect());
    }

    public function testfulljoinkeepsaliasandextracondition(): void
    {
        $this->assertSame(' FROM users  LEFT JOIN typesuser  as t ON (users.typeuserid=t.idtypeuser  AND t.idtypeuser>0) ', FullJoinModel::_sqljoins());
        $this->assertStringContainsString(' , t.typeuser ', FullJoinModel::_sqlselect());
    }
}
