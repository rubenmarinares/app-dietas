<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Pii;
use PHPUnit\Framework\TestCase;

/** Enmascarado de emails para logs. */
final class PiiTest extends TestCase
{
    public function testMaskEmail(): void
    {
        $this->assertSame('a***@example.org', Pii::maskemail('ana@example.org'));
        $this->assertSame('a***@example.org', Pii::maskemail('  ana@example.org '));
        $this->assertSame('***', Pii::maskemail('sin-arroba'));
        $this->assertSame('***', Pii::maskemail('@example.org'));
        $this->assertSame('Á***@example.org', Pii::maskemail('Ángel@example.org'));
    }

    public function testMaskMessagePhpMailer(): void
    {
        $this->assertSame('SMTP Error: The following recipients failed: ***@example.org',
            Pii::maskmessage('SMTP Error: The following recipients failed: ana.perez+x@example.org'));
        $this->assertSame('Invalid address:  (to): ***@example', Pii::maskmessage('Invalid address:  (to): ana@example'));
    }

    public function testMaskMessagePdoYVarios(): void
    {
        $this->assertSame("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '***@example.org' for key 'email'",
            Pii::maskmessage("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'ana@example.org' for key 'email'"));
        $this->assertSame('a: ***@a.es, ***@b.org; <***@c.com> (***@d.net)',
            Pii::maskmessage('a: uno@a.es, dos@b.org; <tres@c.com> (cuatro@d.net)'));
    }

    public function testMaskMessageSinEmailsNoCambia(): void
    {
        $s = 'Error de conexión SMTP: timeout a las 10:00 (intento 2/2) en mail.example.org';
        $this->assertSame($s, Pii::maskmessage($s));
        $this->assertSame('', Pii::maskmessage(''));
    }
}
