<?php
namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use App\Core\SecurityHeaders;

class SecurityHeadersTest extends TestCase
{
    public function testHeadersBasicos(): void
    {
        $h = SecurityHeaders::forpanel(false);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        $this->assertSame('SAMEORIGIN', $h['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $h['Referrer-Policy']);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $h);
    }

    public function testHstsSoloEnHttps(): void
    {
        $this->assertArrayHasKey('Strict-Transport-Security', SecurityHeaders::forpanel(true));
    }

    public function testCspSoloReportOnlyYConCdns(): void
    {
        $h = SecurityHeaders::forpanel(false);
        $this->assertArrayNotHasKey('Content-Security-Policy', $h);
        $csp = $h['Content-Security-Policy-Report-Only'];
        foreach (['https://cdnjs.cloudflare.com', 'https://cdn.datatables.net', 'https://unpkg.com', 'https://cdn.jsdelivr.net', 'https://fonts.googleapis.com', 'https://fonts.gstatic.com'] as $host) {
            $this->assertStringContainsString($host, $csp);
        }
        $this->assertStringContainsString("img-src 'self' data:", $csp);
    }

    public function testIshttps(): void
    {
        $this->assertFalse(SecurityHeaders::ishttps([]));
        $this->assertFalse(SecurityHeaders::ishttps(['HTTPS' => 'off']));
        $this->assertTrue(SecurityHeaders::ishttps(['HTTPS' => 'on']));
        $this->assertTrue(SecurityHeaders::ishttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertTrue(SecurityHeaders::ishttps(['HTTP_X_FORWARDED_PROTO' => 'HTTPS,http']));
        $this->assertFalse(SecurityHeaders::ishttps(['HTTP_X_FORWARDED_PROTO' => 'http']));
    }

    public function testCookieParams(): void
    {
        $p = SecurityHeaders::cookieparams('/app/', true);
        $this->assertSame(['lifetime' => 0, 'path' => '/app/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'], $p);
        $this->assertFalse(SecurityHeaders::cookieparams('/app/', false)['secure']);
    }

    public function testIdleExpired(): void
    {
        $this->assertFalse(SecurityHeaders::idleexpired(null, 1000000));
        $this->assertFalse(SecurityHeaders::idleexpired(1000000 - 7200, 1000000));
        $this->assertTrue(SecurityHeaders::idleexpired(1000000 - 7201, 1000000));
    }
}
