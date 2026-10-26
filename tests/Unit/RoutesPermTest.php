<?php declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RoutesPermTest extends TestCase
{
    // Rutas GET que mutan estado: deben declarar 'perm' => 'write' para que el gate
    // de App no las trate como lectura. Lista mantenida a mano: si añades una ruta
    // GET que muta, añádela aquí y anótala en RoutesApp.php.
    private const MUTATINGGETPATTERNS = [
        // 'pages/{id:\d+}/publish',
    ];

    private static function routes(): array
    {
        return require __DIR__ . '/../../src/RoutesApp.php';
    }

    public function testmutatinggetrouteshavewriteoverride(): void
    {
        $bypattern = [];
        foreach (self::routes() as $route) {
            $bypattern[$route[1]] = $route[2];
        }
        foreach (self::MUTATINGGETPATTERNS as $pattern) {
            $this->assertArrayHasKey($pattern, $bypattern, "Ruta no encontrada: $pattern");
            $this->assertSame('write', $bypattern[$pattern]['perm'] ?? null, "Falta 'perm' => 'write' en: $pattern");
        }
        $this->addToAssertionCount(1);
    }

    public function testeveryroutehasvalidshapeandexistinghandler(): void
    {
        foreach (self::routes() as $route) {
            $this->assertIsArray($route[0], 'Métodos como array: ' . json_encode($route));
            $this->assertIsString($route[1]);
            $this->assertFalse(str_starts_with($route[1], '/'), "El patrón va sin barra inicial ni APP_PATH: {$route[1]}");
            [$class, $method] = [$route[2][0], $route[2][1]];
            $this->assertTrue(class_exists($class), "Clase inexistente: $class");
            $this->assertTrue(method_exists($class, $method), "Método inexistente: $class::$method");
            if (isset($route[2]['perm'])) {
                $this->assertContains($route[2]['perm'], ['read', 'write'], "perm inválido en {$route[1]}");
            }
        }
    }

    public function testloginscreenispublic(): void
    {
        $this->assertContains(\App\Screens\LoginScreen::class, \App\Core\App::PUBLICSCREENS);
    }
}
