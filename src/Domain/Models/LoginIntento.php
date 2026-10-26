<?php
namespace App\Domain\Models;

use App\Core;

/** Fallos de login recientes (límite de intentos). Solo cuenta fallos. */
class LoginIntento extends Core\ModelBase
{
  static public $tablename = 'login_intentos';

  /** Fallos desde $since (por email o por IP). Si la consulta falla, [] (no bloquear el login por un fallo de BBDD). */
  public static function recent($db, string $email, string $ip, int $since): array
  {
    $rows = $db->run("SELECT email, ip, stamp FROM login_intentos WHERE stamp >= ? AND (email = ? OR ip = ?)", [$since, $email, $ip]);
    return is_array($rows) ? $rows : [];
  }

  public static function record($db, string $email, string $ip, int $now): void
  {
    $db->run("INSERT INTO login_intentos (email, ip, stamp) VALUES (?, ?, ?)", [$email, $ip, $now]);
  }

  /** Un login correcto borra los fallos de ese email. */
  public static function clearemail($db, string $email): void
  {
    $db->run("DELETE FROM login_intentos WHERE email = ?", [$email]);
  }

  public static function purgebefore($db, int $stamp): void
  {
    $db->run("DELETE FROM login_intentos WHERE stamp < ?", [$stamp]);
  }
}
