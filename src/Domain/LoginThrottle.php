<?php
namespace App\Domain;

/**
 * Reglas puras del límite de intentos de login (sin BBDD).
 * Bloqueo: 5 fallos por email o 20 por IP dentro de una ventana de 15 minutos.
 */
class LoginThrottle
{
  public const WINDOW = 900;
  public const MAXPEREMAIL = 5;
  public const MAXPERIP = 20;
  public const PURGEAFTER = 86400;

  /**
   * @param array  $attempts fallos registrados: filas con 'email', 'ip', 'stamp' (time())
   * @param string $email    email que intenta entrar ('' = no contar por email)
   * @param string $ip       IP del cliente ('' = no contar por IP)
   */
  public static function isblocked(array $attempts, int $now, string $email = '', string $ip = ''): bool
  {
    $email = self::normalize($email);
    $peremail = 0;
    $perip = 0;
    foreach ($attempts as $a) {
      $age = $now - (int)($a['stamp'] ?? 0);
      if ($age < 0 || $age >= self::WINDOW) continue;
      if ($email !== '' && self::normalize((string)($a['email'] ?? '')) === $email) $peremail++;
      if ($ip !== '' && (string)($a['ip'] ?? '') === $ip) $perip++;
    }
    return $peremail >= self::MAXPEREMAIL || $perip >= self::MAXPERIP;
  }

  /**
   * Clave de los fallos del segundo factor de un usuario en login_intentos (columna email).
   * Distinta del email: un login con contraseña correcta borra los fallos del email, no estos.
   */
  public static function twofakey(int $userid): string
  {
    return '2fa:' . $userid;
  }

  public static function normalize(string $email): string
  {
    return mb_strtolower(trim($email));
  }
}
