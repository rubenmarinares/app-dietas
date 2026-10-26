<?php declare(strict_types=1);

namespace App\Core;

class TokenGenerator
{
    public static function generate(int $bytes = 32): string
    {
        $raw = random_bytes($bytes);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
