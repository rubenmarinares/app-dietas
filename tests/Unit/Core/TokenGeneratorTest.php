<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\TokenGenerator;
use PHPUnit\Framework\TestCase;

class TokenGeneratorTest extends TestCase
{
    public function testGenerateReturnsUrlSafeString(): void
    {
        $t = TokenGenerator::generate();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $t);
        $this->assertGreaterThanOrEqual(43, strlen($t));
    }

    public function testGenerateReturnsUniqueValues(): void
    {
        $a = TokenGenerator::generate();
        $b = TokenGenerator::generate();
        $this->assertNotSame($a, $b);
    }

    public function testHashIsSha256Hex(): void
    {
        $t = 'abc123';
        $this->assertSame(hash('sha256', $t), TokenGenerator::hash($t));
        $this->assertSame(64, strlen(TokenGenerator::hash($t)));
    }
}
