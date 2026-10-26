<?php declare(strict_types=1);

namespace App\Domain;

use App\Core\Mailer;

/**
 * Único camino de envío de email de la aplicación (nunca llamar a Core\Mailer directamente).
 * Modo en MAIL_MODE (.env):
 *   - desactivado (por defecto, y cualquier valor desconocido): no se envía; se anota en storage/mail.log.
 *   - sandbox: no se envía; se anota con el destinatario sustituido por MAIL_SANDBOX_EMAIL.
 *   - real: envío SMTP SOLO si además APP_ENV=production; en cualquier otro entorno, se anota.
 * Así ningún email real sale desde local o staging aunque alguien copie el .env de producción.
 * El log no lleva datos personales ni tokens (solo el dominio del destinatario y el asunto) salvo con
 * APP_ENV local o test explícito, donde lleva el mensaje completo (p. ej. el código 2FA) para poder probar.
 */
class MailDomain
{
    public const MODES = ['desactivado', 'sandbox', 'real'];

    /** Fichero del log de envíos (fuera del web root: .htaccess sirve 404 en /storage/). */
    static public function logfile(): string
    {
        return dirname(__DIR__, 2) . '/storage/mail.log';
    }

    /**
     * Decisión pura: qué hacer con un email.
     * @return array{action:string,to:string,mode:string} action = send | log
     */
    static public function route(string $mode, string $appenv, string $sandboxemail, string $to): array
    {
        $mode = in_array($mode, self::MODES, true) ? $mode : 'desactivado';
        if ($mode === 'real' && $appenv === 'production') return ['action' => 'send', 'to' => $to, 'mode' => $mode];
        if ($mode === 'sandbox') return ['action' => 'log', 'to' => trim($sandboxemail) !== '' ? trim($sandboxemail) : $to, 'mode' => $mode];
        return ['action' => 'log', 'to' => $to, 'mode' => $mode];
    }

    /** Dominio del destinatario ("example.org"), lo único que se anota fuera de local/test. */
    static public function domainof(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    /** ¿Entorno de desarrollo declarado de forma explícita? Solo 'local' o 'test'; vacío o cualquier otro valor, no. */
    static public function islocalenv(string $appenv): bool
    {
        return in_array($appenv, ['local', 'test'], true);
    }

    /**
     * Línea(s) del log (pura). Por defecto (APP_ENV vacío, production, staging…): fecha, modo, dominio
     * del destinatario, asunto y "cuerpo omitido". Solo con APP_ENV local o test explícito, además el
     * destinatario, el original (si sandbox lo sustituyó) y el HTML completo (con los enlaces y tokens).
     */
    static public function logentry(string $date, array $route, string $originalto, string $subject, string $html, string $appenv): string
    {
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $line = '[' . $date . '] modo=' . $route['mode'] . ' dominio=' . self::domainof($originalto) . ' asunto="' . $subject . '"';
        if (!self::islocalenv($appenv)) return $line . " (cuerpo omitido)\n";
        $line .= ' para=' . $route['to'] . ($route['to'] !== $originalto ? ' (original: ' . $originalto . ')' : '');
        return $line . "\n" . $html . "\n" . str_repeat('-', 72) . "\n";
    }

    /**
     * Envía (o anota) un email. true si se entregó al SMTP o se anotó. Nunca lanza: un fallo se registra en
     * error_log (sin el mensaje, que puede llevar direcciones) y devuelve false.
     */
    static public function send(string $to, string $toname, string $subject, string $html, string $text = ''): bool
    {
        try {
            $route = self::route((string) MAIL_MODE, (string) APP_ENV, (string) MAIL_SANDBOX_EMAIL, $to);
            if ($route['action'] === 'send') return Mailer::send($route['to'], $toname, $subject, $html, $text);
            $file = self::logfile();
            if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
            $entry = self::logentry(date('Y-m-d H:i:s'), $route, $to, $subject, $html, (string) APP_ENV);
            return @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
        } catch (\Throwable $e) {
            error_log('[MailDomain] ' . get_class($e) . ' al enviar «' . str_replace(["\r", "\n"], ' ', $subject) . '»');
            return false;
        }
    }
}
