<?php declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Todo email pasa por Domain\MailDomain (modos desactivado/sandbox/real y APP_ENV=production): una llamada
 * directa a Core\Mailer enviaría correo real desde local o staging.
 */
final class MailerUsageTest extends TestCase
{
    public function testonlymaildomaincallsthemailer(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!str_ends_with($path, '.php') || str_contains($path, '/cache/')) continue;
            if (str_ends_with($path, '/Domain/MailDomain.php') || str_ends_with($path, '/Core/Mailer.php')) continue;
            if (preg_match('/\bMailer::send\b/', (string) file_get_contents($path))) $offenders[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
        }
        $this->assertSame([], $offenders, 'Usa MailDomain::send en lugar de Mailer::send');
    }
}
