<?php declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/Deploy/DeployDetect.php';

use Tools\Deploy\DeployDetect;

class DeployDetectTest extends TestCase
{
    public function testNormalizedHashIgnoresLineEndings(): void
    {
        $this->assertSame(DeployDetect::normalizedhash("a\nb\n"), DeployDetect::normalizedhash("a\r\nb\r\n"));
        $this->assertNotSame(DeployDetect::normalizedhash("a\n"), DeployDetect::normalizedhash("b\n"));
    }

    public function testRankReturnsNewestCommitWithoutMismatches(): void
    {
        $remote = ['src/A.php' => 'h2', 'src/B.php' => 'b1'];
        $commits = [
            ['sha' => 'c3', 'files' => ['src/A.php' => 'h2', 'src/B.php' => 'b2']],
            ['sha' => 'c2', 'files' => ['src/A.php' => 'h2', 'src/B.php' => 'b1']],
            ['sha' => 'c1', 'files' => ['src/A.php' => 'h1', 'src/B.php' => 'b1']],
        ];
        $ranking = DeployDetect::rank($remote, $commits);
        $this->assertSame('c2', $ranking[0]['sha']);
        $this->assertSame([], $ranking[0]['mismatches']);
    }

    public function testRankOrdersByFewestMismatchesThenNewest(): void
    {
        $remote = ['src/A.php' => 'x', 'src/B.php' => 'b1'];
        $commits = [
            ['sha' => 'c2', 'files' => ['src/A.php' => 'h2', 'src/B.php' => 'b2']],
            ['sha' => 'c1', 'files' => ['src/A.php' => 'h1', 'src/B.php' => 'b1']],
        ];
        $ranking = DeployDetect::rank($remote, $commits);
        $this->assertSame('c1', $ranking[0]['sha']);
        $this->assertSame(['src/A.php'], $ranking[0]['mismatches']);
    }

    public function testMissingOnBothSidesMatchesMissingOnOneSideDoesNot(): void
    {
        $remote = ['src/New.php' => null];
        $commits = [
            ['sha' => 'c2', 'files' => ['src/New.php' => 'n1']],
            ['sha' => 'c1', 'files' => ['src/New.php' => null]],
        ];
        $ranking = DeployDetect::rank($remote, $commits);
        $this->assertSame('c1', $ranking[0]['sha']);
        $this->assertSame([], $ranking[0]['mismatches']);
    }
}
