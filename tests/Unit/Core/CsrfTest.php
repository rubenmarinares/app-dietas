<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenReturnsStableValueWithinSession(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        $this->assertSame($a, $b);
        $this->assertNotEmpty($a);
    }

    public function testValidateAcceptsSameToken(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::validate($t));
    }

    public function testValidateRejectsDifferent(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::validate('wrong'));
    }

    public function testValidateRejectsEmpty(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::validate(''));
    }

    public function testValidateRejectsNonStringInput(): void
    {
        // Un POST con csrf_token[]=x llega como array: antes lanzaba TypeError (500).
        $t = Csrf::token();
        $this->assertFalse(Csrf::validate([$t]));
        $this->assertFalse(Csrf::validate(null));
        $this->assertFalse(Csrf::validate(123));
    }
}
