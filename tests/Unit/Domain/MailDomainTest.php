<?php declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\MailDomain;
use PHPUnit\Framework\TestCase;

/** Interruptor de envío: ningún email real fuera de `real` + APP_ENV=production. */
final class MailDomainTest extends TestCase
{
    public function testdisabledandunknownmodesonlylog(): void
    {
        foreach (['desactivado', '', 'REAL', 'otro'] as $mode) {
            $route = MailDomain::route($mode, 'production', 'sandbox@example.org', 'ana@example.org');
            $this->assertSame('log', $route['action'], $mode);
            $this->assertSame('desactivado', $route['mode'], $mode);
            $this->assertSame('ana@example.org', $route['to']);
        }
    }

    public function testrealsendsonlyinproduction(): void
    {
        $this->assertSame('send', MailDomain::route('real', 'production', '', 'ana@example.org')['action']);
        foreach (['local', 'staging', '', 'Production'] as $env) {
            $this->assertSame('log', MailDomain::route('real', $env, '', 'ana@example.org')['action'], $env);
        }
    }

    public function testsandboxneversendsandredirects(): void
    {
        $route = MailDomain::route('sandbox', 'production', 'pruebas@example.org', 'ana@example.org');
        $this->assertSame('log', $route['action']);
        $this->assertSame('pruebas@example.org', $route['to']);
    }

    /** Producción, staging, APP_ENV ausente ('') o cualquier valor no explícitamente local/test: sin cuerpo ni destinatario. */
    public function testnonlocallogcarriesnopersonaldataortokens(): void
    {
        foreach (['production', '', 'staging', 'Local', 'dev'] as $env) {
            $route = MailDomain::route('desactivado', $env, '', 'ana.perez@example.org');
            $entry = MailDomain::logentry('2026-10-21 10:00:00', $route, 'ana.perez@example.org', "Asunto\r\nBcc: x", '<p>token=SECRETO</p>', $env);
            $this->assertStringContainsString('dominio=example.org', $entry, $env);
            $this->assertStringContainsString('cuerpo omitido', $entry, $env);
            $this->assertStringNotContainsString('ana.perez', $entry, $env);
            $this->assertStringNotContainsString('SECRETO', $entry, $env);
            $this->assertStringNotContainsString("\r", $entry, $env);
            $this->assertSame(1, substr_count($entry, "\n"), $env);
        }
    }

    public function testislocalenv(): void
    {
        $this->assertTrue(MailDomain::islocalenv('local'));
        $this->assertTrue(MailDomain::islocalenv('test'));
        foreach (['', 'production', 'staging', 'LOCAL'] as $env) $this->assertFalse(MailDomain::islocalenv($env), $env);
    }

    public function testlocallogcarriesthefullmessage(): void
    {
        $route = MailDomain::route('sandbox', 'local', 'pruebas@example.org', 'ana@example.org');
        $entry = MailDomain::logentry('2026-10-21 10:00:00', $route, 'ana@example.org', 'Asunto', '<p>token=SECRETO</p>', 'local');
        $this->assertStringContainsString('para=pruebas@example.org (original: ana@example.org)', $entry);
        $this->assertStringContainsString('SECRETO', $entry);
    }

    public function testdomainof(): void
    {
        $this->assertSame('example.org', MailDomain::domainof('Ana@Example.ORG'));
        $this->assertSame('', MailDomain::domainof('sin-arroba'));
    }

    public function testlogfileisoutsidethewebroot(): void
    {
        $this->assertStringEndsWith('/storage/mail.log', str_replace('\\', '/', MailDomain::logfile()));
    }
}
