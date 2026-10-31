<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private array $hosts = ['www.youtube.com', 'player.vimeo.com'];

    private function clean(string $html): string
    {
        return HtmlSanitizer::clean($html, $this->hosts);
    }

    public function testRemovesScriptHandlersAndJavascriptUrls(): void
    {
        $out = $this->clean('<p onclick="x()">a</p><script>alert(1)</script><a href="javascript:alert(1)">l</a>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testKeepsImageWithWidthAndFloat(): void
    {
        $out = $this->clean('<img src="https://www.example.org/upload/a.jpg" width="300" style="float:left">');
        $this->assertStringContainsString('src="https://www.example.org/upload/a.jpg"', $out);
        $this->assertStringContainsString('width="300"', $out);
        $this->assertStringContainsString('float:left', $out);
    }

    public function testKeepsTableColspanAndTextAlign(): void
    {
        $out = $this->clean('<table><tr><td colspan="2" style="text-align:center">x</td></tr></table>');
        $this->assertStringContainsString('colspan="2"', $out);
        $this->assertStringContainsString('text-align:center', $out);
    }

    public function testKeepsClass(): void
    {
        $this->assertStringContainsString('class="destacado"', $this->clean('<p class="destacado">x</p>'));
    }

    public function testRemovesDisallowedStyleProperties(): void
    {
        $out = $this->clean('<p style="font-family:Calibri;font-size:30px;position:absolute;display:none;background:url(x.png);color:red">x</p>');
        $this->assertStringNotContainsString('font-family', $out);
        $this->assertStringNotContainsString('font-size', $out);
        $this->assertStringNotContainsString('position', $out);
        $this->assertStringNotContainsString('display', $out);
        $this->assertStringNotContainsString('url(', $out);
    }

    public function testKeepsExtendedStyleProperties(): void
    {
        $out = $this->clean('<p style="color:#336699;font-weight:bold;padding:4px;margin-top:2px;border-bottom:1px solid #000;white-space:nowrap">x</p>');
        foreach (['color:#336699', 'font-weight:bold', 'padding:4px', 'margin-top:2px', 'border-bottom:1px solid #000', 'white-space:nowrap'] as $css) {
            $this->assertStringContainsString($css, $out);
        }
    }

    public function testKeepsVideoWithSource(): void
    {
        $out = $this->clean('<video controls width="640" height="360" poster="https://www.example.org/p.jpg" preload="none"><source src="https://www.example.org/v.mp4" type="video/mp4"></video>');
        $this->assertStringContainsString('<video', $out);
        $this->assertStringContainsString('controls', $out);
        $this->assertStringContainsString('width="640"', $out);
        $this->assertStringContainsString('poster="https://www.example.org/p.jpg"', $out);
        $this->assertStringContainsString('<source', $out);
        $this->assertStringContainsString('src="https://www.example.org/v.mp4"', $out);
        $this->assertStringContainsString('type="video/mp4"', $out);
    }

    public function testVideoBlocksJavascriptUrls(): void
    {
        $out = $this->clean('<video src="javascript:alert(1)"><source src="javascript:alert(2)" type="video/mp4"></video>');
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testKeepsIdsAndAnchorNames(): void
    {
        $out = $this->clean('<h3 id="seccion1">T</h3><a name="ancla">x</a><table id="t1"><tr><td>1</td></tr></table>');
        $this->assertStringContainsString('id="seccion1"', $out);
        $this->assertStringContainsString('name="ancla"', $out);
        $this->assertStringContainsString('id="t1"', $out);
    }

    public function testKeepsImageSizeAsPercentOrPixels(): void
    {
        $out = $this->clean('<img src="https://www.example.org/a.jpg" width="100%" height="300px" alt="a">');
        $this->assertStringContainsString('width="100%"', $out);
        // HTMLPurifier normaliza "300px" a "300" (mismo valor en píxeles).
        $this->assertStringContainsString('height="300"', $out);
        $tbl = $this->clean('<table width="50%"><tr><td width="30%">x</td></tr></table>');
        $this->assertStringContainsString('width="50%"', $tbl);
        $this->assertStringContainsString('width="30%"', $tbl);
    }

    public function testKeepsClassOnAnchor(): void
    {
        $this->assertStringContainsString('class="boton"', $this->clean('<a class="boton" href="https://x.org">l</a>'));
    }

    public function testIframeAllowlist(): void
    {
        $ok = $this->clean('<iframe src="https://www.youtube.com/embed/abc"></iframe>');
        $this->assertStringContainsString('<iframe', $ok);
        $this->assertStringContainsString('youtube.com/embed/abc', $ok);

        $bad = $this->clean('<iframe src="https://evil.example/x"></iframe>');
        $this->assertStringNotContainsString('evil.example', $bad);
    }

    public function testIframeHostMustMatchExactly(): void
    {
        $out = $this->clean('<iframe src="https://www.youtube.com.evil.example/embed/abc"></iframe>');
        $this->assertStringNotContainsString('evil.example', $out);
    }

    public function testNoIframesWithoutHosts(): void
    {
        $out = HtmlSanitizer::clean('<iframe src="https://www.youtube.com/embed/abc"></iframe>');
        $this->assertStringNotContainsString('<iframe', $out);
    }

    public function testKeepsAllowfullscreenAndTargetBlank(): void
    {
        $ok = $this->clean('<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>');
        $this->assertStringContainsString('allowfullscreen', $ok);
        $this->assertStringContainsString('target="_blank"', $this->clean('<a href="https://x.org" target="_blank">l</a>'));
        $upper = $this->clean('<iframe src="HTTPS://WWW.YOUTUBE.COM/embed/abc"></iframe>');
        $this->assertStringContainsString('embed/abc', $upper);
    }

    public function testKeepsAccentsAndEntities(): void
    {
        $out = $this->clean('<p>Diabetes &aacute; ñ</p>');
        $this->assertTrue(str_contains($out, 'á') || str_contains($out, '&aacute;'));
        $this->assertStringContainsString('ñ', $out);
    }

    public function testCacheLivesInsideProject(): void
    {
        $this->clean('<p>x</p>');
        $dir = dirname(__DIR__, 3) . '/src/cache/htmlpurifier';
        $this->assertDirectoryExists($dir);
        $this->assertNotEmpty(glob($dir . '/*/*') ?: glob($dir . '/*'), 'la caché serializada está en el proyecto');
    }
}
