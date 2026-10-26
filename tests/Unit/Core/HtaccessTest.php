<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** Reglas de seguridad del .htaccess raíz y scripts ejecutables en public/. */
final class HtaccessTest extends TestCase
{
    private function htaccess(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
    }

    /** Solo se ejecutan los dos front controllers: ni restos de la plantilla ni scripts sueltos en el docroot. */
    public function testonlyfrontcontrollersexecute(): void
    {
        $this->assertStringContainsString(
            '<If "%{REQUEST_URI} !~ m#^/public/(index|app)\.php$# && %{REQUEST_FILENAME} =~ m#\.(php\d*|phtml|pht|phps|phar|inc|cgi|pl|py|sh|shtml)$#i && -f %{REQUEST_FILENAME}">',
            $this->htaccess()
        );
    }

    /** La plantilla traía public/assets/json/file-upload.php: subida de ficheros sin autenticación. */
    public function testpublichasonlythefrontcontrollerscripts(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/public", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (preg_match('/\.(php\d*|phtml|pht|phar)$/i', $file->getFilename())) {
                $found[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
            }
        }
        sort($found);
        // router.php solo sirve a `php -S` en local: el .htaccess no lo deja ejecutar en el hosting.
        $this->assertSame(['public/app.php', 'public/index.php', 'public/router.php'], $found);
    }

    /** La portada va explícita al front controller (DirectoryIndex de /public/ no pasaría la regla de scripts). */
    public function testhomereachesfrontcontroller(): void
    {
        $this->assertStringContainsString('RewriteRule ^$ /public/index.php [L]', $this->htaccess());
    }

    public function testforcehttpsexceptlocalhost(): void
    {
        $ht = $this->htaccess();
        $this->assertStringContainsString('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]', $ht);
        $this->assertStringContainsString('%{HTTP:X-Forwarded-Proto} !=https', $ht);
        $this->assertStringContainsString('localhost', $ht);
        $this->assertLessThan(strpos($ht, 'RewriteRule ^app/'), strpos($ht, 'RewriteRule ^ https://'));
    }

    public function testsensitivefilesandfoldersblocked(): void
    {
        $ht = $this->htaccess();
        $this->assertStringContainsString('RedirectMatch 404 ^/(src|tests|tools|migrations|vendor|docs|uploads|storage)(/|$)', $ht);
        $this->assertStringContainsString('^\.env', $ht);
    }

    public function testpublicfallbackdoesnotloop(): void
    {
        $ht = $this->htaccess();
        $rule = strpos($ht, 'RewriteRule ^(.*)$ /public/$1 [L,QSA]');
        $this->assertNotFalse($rule);
        $this->assertLessThan($rule, strrpos(substr($ht, 0, $rule), 'RewriteCond %{REQUEST_URI} !^/public/'));
    }
}
