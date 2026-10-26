<?php declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\PermissionDomain;
use PHPUnit\Framework\TestCase;

class PermissionDomainTest extends TestCase
{
    // --- permissionsforuser sin tipo de usuario (no consulta la BBDD) ---

    public function testuserwithouttypefailsclosed(): void
    {
        $db = new \PDO('sqlite::memory:');
        $this->assertSame([], PermissionDomain::permissionsforuser($db, ['typeuserid' => 0]));
        $this->assertSame([], PermissionDomain::permissionsforuser($db, ['iduser' => 5]));
        $this->assertSame([], PermissionDomain::permissionsforuser($db, null));
    }

    // --- modulefromclass ---

    public function testmodulefromclassstripsnamespaceandscreensuffix(): void
    {
        $this->assertSame('settings', PermissionDomain::modulefromclass('App\Screens\SettingsScreen'));
        $this->assertSame('pages', PermissionDomain::modulefromclass('App\Screens\PagesScreen'));
    }

    public function testmodulefromclasslowercasesclasseswithoutsuffix(): void
    {
        $this->assertSame('webhookcontroller', PermissionDomain::modulefromclass('App\Controllers\WebhookController'));
    }

    public function testindexandprofilearealwaysallowed(): void
    {
        $this->assertTrue(PermissionDomain::isalwaysallowed(PermissionDomain::modulefromclass('App\Screens\IndexScreen')));
        $this->assertTrue(PermissionDomain::isalwaysallowed(PermissionDomain::modulefromclass('App\Screens\UserProfileScreen')));
        $this->assertFalse(PermissionDomain::isalwaysallowed('settings'));
    }

    // --- requiredlevelformethod ---

    public function testgetandheadrequireread(): void
    {
        $this->assertSame('read', PermissionDomain::requiredlevelformethod('GET'));
        $this->assertSame('read', PermissionDomain::requiredlevelformethod('get'));
        $this->assertSame('read', PermissionDomain::requiredlevelformethod('HEAD'));
    }

    public function testmutatingmethodsrequirewrite(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
            $this->assertSame('write', PermissionDomain::requiredlevelformethod($m));
        }
    }

    // --- levelfor ---

    public function testnullpermissionsmeansfullaccess(): void
    {
        $this->assertSame('write', PermissionDomain::levelfor(null, 'settings'));
    }

    public function testexactmodulewinsoverwildcard(): void
    {
        $perms = ['pages' => 'write', '*' => 'read'];
        $this->assertSame('write', PermissionDomain::levelfor($perms, 'pages'));
        $this->assertSame('read', PermissionDomain::levelfor($perms, 'settings'));
    }

    public function testnomatchandnowildcardmeansnone(): void
    {
        $this->assertSame('none', PermissionDomain::levelfor(['pages' => 'read'], 'settings'));
    }

    public function testinvalidlevelvaluemeansnone(): void
    {
        $this->assertSame('none', PermissionDomain::levelfor(['*' => 'superadmin'], 'pages'));
        $this->assertSame('none', PermissionDomain::levelfor(['*' => ['nested' => 'array']], 'pages'));
    }

    // --- can ---

    public function testwriteimpliesread(): void
    {
        $this->assertTrue(PermissionDomain::can(['*' => 'write'], 'pages', 'read'));
    }

    public function testreaddoesnotimplywrite(): void
    {
        $this->assertFalse(PermissionDomain::can(['*' => 'read'], 'pages', 'write'));
        $this->assertTrue(PermissionDomain::can(['*' => 'read'], 'pages', 'read'));
    }

    public function testnoneblockseverything(): void
    {
        $this->assertFalse(PermissionDomain::can(['*' => 'none'], 'pages', 'read'));
    }

    public function testreadonlyscenario(): void
    {
        $perms = ['*' => 'read'];
        $this->assertTrue(PermissionDomain::can($perms, 'pages', PermissionDomain::requiredlevelformethod('GET')));
        $this->assertFalse(PermissionDomain::can($perms, 'pages', PermissionDomain::requiredlevelformethod('POST')));
    }

    // --- parsepermissionsinput ---

    public function testemptyinputmeansnull(): void
    {
        $this->assertNull(PermissionDomain::parsepermissionsinput(''));
        $this->assertNull(PermissionDomain::parsepermissionsinput('   '));
    }

    public function testvalidjsonisparsedandkeysnormalized(): void
    {
        $this->assertSame(['*' => 'read'], PermissionDomain::parsepermissionsinput('{"*": "read"}'));
        $this->assertSame(['pages' => 'write'], PermissionDomain::parsepermissionsinput('{"Pages": "write"}'));
    }

    public function testinvalidinputsthrow(): void
    {
        foreach (['{esto no es json', '"cadena"', '{"*": "superadmin"}', '[1,2,3]'] as $input) {
            try {
                PermissionDomain::parsepermissionsinput($input);
                $this->fail("Debería rechazar: $input");
            } catch (\InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
