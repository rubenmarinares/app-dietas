<?php
namespace App\Core;

/**
 * Endurecimiento HTTP del panel: cabeceras de seguridad y cookie de sesión.
 * La parte pura (forpanel, ishttps) se testea sin servidor; send/startsession
 * tocan header()/session_start().
 */
class SecurityHeaders
{
  /** Segundos de inactividad tras los que se cierra la sesión (2 h). */
  public const IDLETIMEOUT = 7200;

  /**
   * ¿Petición HTTPS? Acepta HTTPS=on/1 o X-Forwarded-Proto=https (proxy inverso).
   * @param array $server normalmente $_SERVER
   */
  public static function ishttps(array $server): bool
  {
    $https = strtolower((string)($server['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') return true;
    $proto = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
    // Puede venir una lista ("https,http"): vale el primer valor.
    $proto = trim(explode(',', $proto)[0]);
    return $proto === 'https';
  }

  /**
   * Cabeceras del panel: nombre => valor.
   *
   * CSP en modo REPORT-ONLY (no bloquea nada): primer paso. Permite 'unsafe-inline'
   * porque el tema y las plantillas usan scripts/estilos en línea; endurecer
   * (nonces, quitar unsafe-inline) y pasar a enforcing es trabajo posterior.
   */
  public static function forpanel(bool $https): array
  {
    $csp = [
      "default-src 'self'",
      "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.datatables.net https://unpkg.com https://cdn.jsdelivr.net",
      "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.datatables.net https://unpkg.com https://cdn.jsdelivr.net https://fonts.googleapis.com",
      "font-src 'self' data: https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://fonts.gstatic.com",
      "img-src 'self' data: blob:",
      "connect-src 'self'",
      "frame-src 'self'",
      "object-src 'none'",
      "base-uri 'self'",
      "form-action 'self'",
      "frame-ancestors 'self'",
    ];
    $headers = [
      'X-Content-Type-Options' => 'nosniff',
      'X-Frame-Options' => 'SAMEORIGIN',
      'Referrer-Policy' => 'strict-origin-when-cross-origin',
      'Content-Security-Policy-Report-Only' => implode('; ', $csp),
    ];
    if ($https) $headers['Strict-Transport-Security'] = 'max-age=31536000';
    return $headers;
  }

  /** ¿Sesión caducada por inactividad? $last = marca de la última petición (null = sin marca, no caduca). */
  public static function idleexpired(?int $last, int $now, int $max = self::IDLETIMEOUT): bool
  {
    return $last !== null && $now - $last > $max;
  }

  /** Emite las cabeceras del panel (si aún se puede). */
  public static function send(array $server): void
  {
    if (headers_sent()) return;
    foreach (self::forpanel(self::ishttps($server)) as $name => $value) header($name . ': ' . $value);
  }

  /** Parámetros de la cookie de sesión. */
  public static function cookieparams(string $path, bool $https): array
  {
    return ['lifetime' => 0, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
  }

  /** Arranca la sesión con cookie endurecida y modo estricto (ids no emitidos por el servidor se rechazan). */
  public static function startsession(string $path, array $server): void
  {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(self::cookieparams($path, self::ishttps($server)));
    session_start();
  }
}
