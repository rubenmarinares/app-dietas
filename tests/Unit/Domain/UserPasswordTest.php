<?php declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Models\User;
use PHPUnit\Framework\TestCase;

class UserPasswordTest extends TestCase
{
    public function testEncryptUsesPasswordHashNotMd5(): void
    {
        $hash = User::encrypt('secreto');
        $this->assertNotSame(md5('secreto'), $hash);
        $this->assertTrue(password_verify('secreto', $hash));
        $this->assertFalse(User::islegacyhash($hash));
    }

    public function testDetectsLegacyMd5Hash(): void
    {
        $this->assertTrue(User::islegacyhash(md5('x')));
        $this->assertTrue(User::islegacyhash(strtoupper(md5('x'))));
        $this->assertFalse(User::islegacyhash(''));
        $this->assertFalse(User::islegacyhash(null));
        $this->assertFalse(User::islegacyhash(str_repeat('z', 32)));
    }
}
