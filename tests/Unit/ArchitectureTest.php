<?php declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reglas de capas verificadas estáticamente:
 *  - Domain no escribe SQL (usa Models).
 *  - Screens no usan Domain\Models (Screen → Domain → Model).
 */
class ArchitectureTest extends TestCase
{
    public function testDomainClassesHaveNoRawSql(): void
    {
        $root = realpath(__DIR__ . '/../../src/Domain');
        $violations = [];

        // Domain y subcarpetas, excepto Domain/Models (allí sí puede haber SQL).
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') continue;
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($rel, 'Models/')) continue;
            $src = file_get_contents($file->getPathname());
            $patterns = [
                '/\$db->(prepare|query|exec|run|runone|update|insert|delete)\b/',
                '/\$app->db->(prepare|query|exec|run|runone|update|insert|delete)\b/',
                '/Database::getInstance\(\)->(prepare|query|exec|run|runone)\b/',
            ];
            foreach ($patterns as $p) {
                if (preg_match_all($p, $src, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[0] as $match) {
                        $line = substr_count(substr($src, 0, $match[1]), "\n") + 1;
                        $violations[] = $rel . ':' . $line . ' -> ' . $match[0];
                    }
                }
            }
        }

        $this->assertSame([], $violations,
            "Las clases Domain no deben escribir SQL. Violaciones:\n - " . implode("\n - ", $violations));
    }

    public function testScreensDoNotUseModels(): void
    {
        $violations = [];
        foreach (glob(__DIR__ . '/../../src/Screens/*.php') as $file) {
            if (preg_match('/Domain\\\\Models/', file_get_contents($file))) {
                $violations[] = basename($file);
            }
        }
        $this->assertSame([], $violations, 'Las Screens no deben usar Domain\\Models: ' . implode(', ', $violations));
    }
}
