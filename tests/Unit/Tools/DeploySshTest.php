<?php declare(strict_types=1);

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../tools/Deploy/DeploySsh.php';

use Tools\Deploy\DeploySsh;

/** Comandos remotos del despliegue por SSH (lógica pura: sin ssh ni git). */
final class DeploySshTest extends TestCase
{
    public function testextractcommandquotesrootandarchive(): void
    {
        $this->assertSame(
            "mkdir -p 'www' && tar -xzf '.deploy-abc.tar.gz' -C 'www' && rm -f '.deploy-abc.tar.gz'",
            DeploySsh::extractcommand('www', '.deploy-abc.tar.gz')
        );
    }

    public function testdeletecommandquoteseverypath(): void
    {
        $this->assertSame(
            "cd 'www' && rm -f -- 'src/Old.php' 'public/a b.css' 'src/it'\\''s.php'",
            DeploySsh::deletecommand('www', ['src/Old.php', 'public/a b.css', "src/it's.php"])
        );
        $this->assertSame('', DeploySsh::deletecommand('www', []));
    }

    public function testmarkercommands(): void
    {
        $this->assertSame("cat 'www/.deployed_commit' 2>/dev/null", DeploySsh::readmarkercommand('www'));
        $this->assertSame("printf '%s' 'abc123' > 'www/.deployed_commit'", DeploySsh::writemarkercommand('www', 'abc123'));
    }

    /** @dataProvider unsafe */
    public function testrejectsunsafepaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeploySsh::safepath($path);
    }

    public static function unsafe(): array
    {
        return [['../etc/passwd'], ['/etc/passwd'], ['src/../../x'], [''], ["src/a\nb.php"], ['-rf']];
    }

    public function testsafepathacceptsrelativepaths(): void
    {
        $this->assertSame('src/Core/App.php', DeploySsh::safepath('src/Core/App.php'));
        $this->assertSame('.htaccess', DeploySsh::safepath('.htaccess'));
    }

    public function testsshargs(): void
    {
        $env = ['SSH_HOST' => 'example.org', 'SSH_USER' => 'deploy', 'SSH_PORT' => '2222', 'SSH_KEY' => 'C:/keys/id'];
        $this->assertSame(['-p', '2222', '-i', 'C:/keys/id', '-o', 'BatchMode=yes', 'deploy@example.org'], DeploySsh::sshargs($env));
        $this->assertSame(['-P', '2222', '-i', 'C:/keys/id', '-o', 'BatchMode=yes'], DeploySsh::scpargs($env));
        $this->assertSame(['-o', 'BatchMode=yes', 'deploy@example.org'], DeploySsh::sshargs(['SSH_HOST' => 'example.org', 'SSH_USER' => 'deploy']));
    }
}
