<?php declare(strict_types=1);

namespace App\Core;

class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = TokenGenerator::generate();
        }
        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Acepta cualquier valor porque suele venir directo de $_POST: un campo enviado
     * como array (csrf_token[]=x) es inválido, no un error 500.
     */
    public static function validate(mixed $token): bool
    {
        if (!is_string($token) || $token === '') return false;
        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        return $stored !== '' && hash_equals($stored, $token);
    }

    public static function rotate(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }
}
