<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

/** APP_URL es la base absoluta de los enlaces que salen de la aplicación: siempre definida y sin barra final. */
final class AppUrlConstantTest extends TestCase
{
    public function testappurlisdefinedwithouttrailingslash(): void
    {
        $this->assertTrue(defined('APP_URL'));
        $this->assertStringEndsNotWith('/', (string) constant('APP_URL'));
    }
}
