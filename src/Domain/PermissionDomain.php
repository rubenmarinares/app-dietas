<?php
namespace App\Domain;

use App\Domain\Models\{TypeUser, UserLog};

class PermissionDomain
{
  const LEVELS = ['none' => 0, 'read' => 1, 'write' => 2];

  // Pantallas que comparten permisos con otro módulo: ['pantalla' => 'modulo'].
  // Ejemplo: ['pagesmedia' => 'pages'] hace que PagesmediaScreen use los permisos de "pages".
  const MODULEALIASES = [];

  // Módulos accesibles para CUALQUIER usuario autenticado, tenga los permisos que tenga:
  // la portada del panel y el perfil propio (cambiar contraseña, configurar 2FA).
  // Sin esto, un tipo {"settings":"write"} recibía 403 hasta en /index.
  const ALWAYSALLOWED = ['index', 'userprofile'];

  static public function isalwaysallowed(string $module): bool
  {
    return in_array(strtolower($module), self::ALWAYSALLOWED, true);
  }

  // App\Screens\SettingsScreen -> "settings"
  static public function modulefromclass(string $classname): string
  {
    $pos = strrpos($classname, '\\');
    $short = $pos === false ? $classname : substr($classname, $pos + 1);
    $module = strtolower(preg_replace('/Screen$/', '', $short));
    return self::MODULEALIASES[$module] ?? $module;
  }

  static public function requiredlevelformethod(string $httpmethod): string
  {
    return in_array(strtoupper($httpmethod), ['GET', 'HEAD'], true) ? 'read' : 'write';
  }

  // null = sin restricciones (retrocompatibilidad con tipos sin permisos definidos).
  static public function levelfor(?array $permissions, string $module): string
  {
    if ($permissions === null) return 'write';
    $module = strtolower($module);
    $level = $permissions[$module] ?? $permissions['*'] ?? 'none';
    if (!is_string($level) || !isset(self::LEVELS[$level])) return 'none';
    return $level;
  }

  static public function can(?array $permissions, string $module, string $requiredlevel): bool
  {
    $current = self::LEVELS[self::levelfor($permissions, $module)];
    $required = self::LEVELS[$requiredlevel] ?? PHP_INT_MAX;
    return $current >= $required;
  }

  // Valida el JSON del textarea de Settings. '' => null (acceso total).
  static public function parsepermissionsinput(string $input): ?array
  {
    $input = trim($input);
    if ($input === '') return null;
    $decoded = json_decode($input, true);
    if (!is_array($decoded)) throw new \InvalidArgumentException('JSON de permisos inválido. Ejemplo: {"*": "read"}');
    $result = [];
    foreach ($decoded as $module => $level) {
      if (!is_string($module) || trim($module) === '') throw new \InvalidArgumentException('Módulo inválido en permisos');
      if (!is_string($level) || !isset(self::LEVELS[$level])) throw new \InvalidArgumentException('Nivel inválido "'.(is_string($level)?$level:gettype($level)).'": solo none, read o write');
      $result[strtolower(trim($module))] = $level;
    }
    return $result;
  }

  // Carga por request (no sesión): una bajada de permisos aplica de inmediato.
  static public function permissionsforuser(\PDO $db, ?array $user): ?array
  {
    $typeuserid = intval($user['typeuserid'] ?? 0);
    if ($typeuserid === 0) return null;
    $row = TypeUser::get($db, $typeuserid);
    if (!$row) return null;
    $decoded = $row['permissions'] ?? null;
    if ($decoded === null) return null;
    if (!is_array($decoded)) return []; // JSON no-objeto => fail closed (none para todo)
    return $decoded;
  }

  static public function logdenied(\PDO $db, ?array $user): void
  {
    UserLog::record($db, intval($user['iduser'] ?? 0), 'denied');
  }
}
