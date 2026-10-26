<?php declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guarda: los metodos estaticos de Domain cuyo primer parametro es `$app`
 * leen `$app->db`; pasarles un PDO provoca "prepare() on null" en produccion.
 */
class AppParamCallersTest extends TestCase
{
    public function testNoCallerPassesPdoToAppParamMethods(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $methods = []; // shortclass => [method => true]

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Domain', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') continue;
            $src = file_get_contents($f->getPathname());
            if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns) || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cl)) continue;
            $fqcn = $ns[1] . chr(92) . $cl[1];
            if (!class_exists($fqcn)) continue;
            foreach ((new \ReflectionClass($fqcn))->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $fqcn || !$m->isStatic()) continue;
                $p = $m->getParameters();
                if ($p && $p[0]->getName() === 'app') $methods[$cl[1]][$m->getName()] = true;
            }
        }
        $this->assertNotEmpty($methods);

        $offenders = [];
        $re = '/\b(\w+)::(\w+)\(\s*(\$db\b|\$this->db\b|\x5c?(?:App\x5cCore\x5c)?Database::getInstance\(\))/';
        foreach (['src', 'public', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() !== 'php' || realpath($f->getPathname()) === realpath(__FILE__)) continue;
                $src = file_get_contents($f->getPathname());
                if (!preg_match_all($re, $src, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) continue;
                foreach ($mm as $m) {
                    if (!isset($methods[$m[1][0]][$m[2][0]])) continue;
                    $line = substr_count(substr($src, 0, $m[0][1]), "\n") + 1;
                    $offenders[] = substr($f->getPathname(), strlen($root) + 1) . ':' . $line . ' ' . $m[1][0] . '::' . $m[2][0];
                }
            }
        }
        $this->assertSame([], $offenders, "Llamadas pasando PDO donde se espera \$app:\n" . implode("\n", $offenders));
    }
}
