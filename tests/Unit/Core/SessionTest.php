<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testSetGetDelete(): void
    {
        $s = new Session();
        $s->set('a', 1);
        $this->assertSame(1, $s->get('a'));
        $s->delete('a');
        $this->assertNull($s->get('a'));
        // delete de una clave inexistente no falla
        $s->delete('nope');
        $this->assertNull($s->get('nope'));
    }

    public function testDestroyEmptiesSessionArray(): void
    {
        $s = new Session();
        $s->set('logado', true);
        $s->destroy();
        $this->assertSame([], $_SESSION);
    }
}
