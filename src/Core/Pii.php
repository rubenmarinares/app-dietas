<?php declare(strict_types=1);

namespace App\Core;

/**
 * Enmascarado de datos personales para logs (puro). Nunca debe llegar un email completo a error_log: los mensajes
 * de PHPMailer ("Invalid address: (to) x@y") y de PDO ("Duplicate entry 'x@y' for key …") los llevan.
 */
final class Pii
{
    /** Patrón de un email dentro de texto libre (parte local y dominio sin espacios, comillas ni paréntesis). */
    private const EMAILRE = '~[^\s\'"<>()\[\],;:]+@([^\s\'"<>()\[\],;:@]+)~u';

    /** "ana@example.org" → "a***@example.org"; sin "@" (o "@" inicial) → "***". */
    static public function maskemail(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) return '***';
        return mb_substr($email, 0, 1, 'UTF-8') . '***' . substr($email, $at);
    }

    /** Texto libre con cada email enmascarado ("***@dominio": ni la inicial, para no ayudar a reidentificar). */
    static public function maskmessage(string $text): string
    {
        return preg_replace(self::EMAILRE, '***@$1', $text) ?? '[mensaje omitido]';
    }
}
