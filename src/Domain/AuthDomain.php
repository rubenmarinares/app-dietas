<?php
namespace App\Domain;

use App\Domain\Models\{User, UserLog, TwoFactorModel, TwoFactorEmailModel, LoginIntento};

/**
 * Autenticación: credenciales + 2FA (TOTP o código por email).
 *
 * Flujo de login (LoginScreen):
 *   1. verifycredentials()  → fila de users o false. NO abre sesión.
 *   2a. Con 2FA configurado → la Screen guarda '2fa_pending' y pide el código.
 *   2b. Sin 2FA y en plazo de gracia (users.deadline2fa >= hoy) → completelogin().
 *   2c. Sin 2FA y plazo vencido/NULL → acceso denegado.
 *   3. validate2FA() correcto → completelogin().
 */
class AuthDomain  {

  /** Comprueba email+contraseña (con migración transparente md5 → password_hash). */
  public const THROTTLEMESSAGE = 'Demasiados intentos. Inténtalo de nuevo en unos minutos.';

  /**
   * Comprueba email+contraseña (con migración transparente md5 → password_hash).
   * Límite de intentos: bloqueado ⇒ LoginThrottledException con mensaje genérico (sin decir si el
   * email existe). Solo se cuentan los fallos; un acceso correcto borra los de ese email.
   * Si la tabla login_intentos falla, el login sigue funcionando (sin límite).
   */
  static public function verifycredentials($app, $email, $pass){
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $now = time();
    $norm = LoginThrottle::normalize((string)$email);
    $recent = LoginIntento::recent($app->db, $norm, $ip, $now - LoginThrottle::WINDOW);
    if (LoginThrottle::isblocked($recent, $now, $norm, $ip)) {
      throw new LoginThrottledException(self::THROTTLEMESSAGE);
    }
    // El intento se registra ANTES de verificar la contraseña: una ráfaga de peticiones paralelas ve los
    // intentos de las demás y no supera el límite. Un login correcto borra los fallos de ese email.
    LoginIntento::record($app->db, $norm, $ip, $now);
    $item = User::login($app->db, $email, $pass);
    if ($item) {
      LoginIntento::clearemail($app->db, $norm);
      return $item;
    }
    if (random_int(1, 20) === 1) LoginIntento::purgebefore($app->db, $now - LoginThrottle::PURGEAFTER);
    return false;
  }

  /**
   * Abre la sesión del usuario y registra el acceso. Guarda en sesión la huella de su contraseña actual:
   * App::__construct la compara en cada petición (sessionuser) para cerrar la sesión si cambia.
   */
  static public function completelogin($app, array $item){
    $row = User::get($app->db, (int) $item["iduser"]);
    $app->setUser($item, self::fingerprint((string) ($row['password'] ?? '')));
    UserLog::record($app->db, (int) $item["iduser"], 'login');
    return $item;
  }

  /** Huella de un hash de contraseña para guardar en sesión (nunca el hash). */
  public static function fingerprint(string $hash): string
  {
    return hash('sha256', 'session-fp|' . $hash);
  }

  /**
   * ¿Sigue valiendo la sesión? (pura) Solo si el usuario existe, está activo (status 1), tiene contraseña y la
   * huella guardada al iniciar sesión coincide con la de su contraseña actual.
   */
  public static function sessionvalid(?array $row, ?string $fingerprint): bool
  {
    if ($row === null || $row === [] || $fingerprint === null || $fingerprint === '') return false;
    if ((int) ($row['status'] ?? 0) !== 1) return false;
    $hash = (string) ($row['password'] ?? '');
    return $hash !== '' && hash_equals(self::fingerprint($hash), $fingerprint);
  }

  /**
   * Usuario actual de una sesión abierta, releído de la BBDD (sin contraseña), o null si la sesión ya no vale:
   * usuario borrado o desactivado, o contraseña cambiada desde el login. Así un cambio de tipo de usuario
   * también aplica en la siguiente petición.
   */
  public static function sessionuser($db, int $iduser, ?string $fingerprint): ?array
  {
    $row = User::get($db, $iduser);
    if (!self::sessionvalid($row ?: null, $fingerprint)) return null;
    unset($row['password']);
    return $row;
  }

  /** Credenciales + apertura de sesión en un paso (sin 2FA). Útil para tests/CLI. */
  static public function login($app,$email,$pass){
    $item = self::verifycredentials($app, $email, $pass);
    if($item) return self::completelogin($app, $item);
    return false;
  }

  static public function logout($app){
    $app->session->destroy();
    return true;
  }

  public static function generateTOTPSecret(): string
  {
    $totp = \OTPHP\TOTP::create();
    return $totp->getSecret();
  }

  public static function validateTOTP(string $secret, string $code): bool
  {
    try {
      $totp = \OTPHP\TOTP::create($secret);
      // verify() acepta un leeway en segundos; 1 ventana = 30s.
      return $totp->verify($code, null, 1);
    } catch (\Exception $e) {
      return false;
    }
  }

  /** URI otpauth:// para dar de alta el secreto en una app autenticadora. */
  public static function provisioninguri(string $secret, string $email): string
  {
    $totp = \OTPHP\TOTP::create($secret);
    $totp->setLabel($email);
    $totp->setIssuer(APP_NAME);
    return $totp->getProvisioningUri();
  }

  /**
   * OJO: NO genera un QR escaneable. Devuelve un SVG con el texto de la URI
   * (herencia de admin.redgdps). La plantilla muestra además el secreto y la URI
   * para alta manual. Ver docs/aprendizajes.md ("QR de 2FA falso").
   */
  public static function generateQRCode(string $secret, string $email): string
  {
    try {
      $encoded = htmlspecialchars(self::provisioninguri($secret, $email), ENT_QUOTES, 'UTF-8');
      return "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><text x='10' y='20' font-size='8'>" . $encoded . "</text></svg>";
    } catch (\Exception $e) {
      throw new \RuntimeException('Failed to generate QR code: ' . $e->getMessage());
    }
  }

  public static function send2FAEmail($db, $userid): void
  {
    $user = User::get($db, $userid);
    if (!$user || !$user['email']) {
      throw new \RuntimeException('User email not found');
    }

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    TwoFactorEmailModel::delete($db, $userid);

    TwoFactorEmailModel::insert($db, [
      'userid'     => $userid,
      'code'       => $code,
      'created_at' => time(),
      'attempts'   => 0,
    ]);

    \App\Core\Mailer::send(
      $user['email'],
      $user['name'] ?? '',
      'Código de autenticación — ' . APP_NAME,
      "Tu código de verificación es: <strong>$code</strong>",
      "Tu código de verificación es: $code"
    );
  }

  public static function validateEmailCode($db, $userid, string $code): bool
  {
    $record = TwoFactorEmailModel::get($db, $userid);

    if (!$record) {
      return false;
    }

    if ((int) $record['attempts'] >= 3) {
      $lockedUntil = (int) $record['created_at'] + 900; // 15 minutos
      if (time() < $lockedUntil) {
        throw new \Exception('Demasiados intentos. Intenta más tarde.');
      }
      // Bloqueo expirado: eliminar y rechazar
      TwoFactorEmailModel::delete($db, $userid);
      return false;
    }

    if (time() - (int) $record['created_at'] > 300) { // 5 minutos
      TwoFactorEmailModel::delete($db, $userid);
      return false;
    }

    if (!hash_equals((string) $record['code'], $code)) {
      TwoFactorEmailModel::updateAttempts($db, $userid, (int) $record['attempts'] + 1);
      return false;
    }

    TwoFactorEmailModel::delete($db, $userid);
    return true;
  }

  public static function setup2FA($db, $userid, $primary_method, $backup_method, $totp_secret = null, $backup_email = null)
  {
    if (!in_array($primary_method, ['totp', 'email'])) {
      throw new \RuntimeException('Invalid primary method');
    }
    if (!in_array($backup_method, ['totp', 'email'])) {
      throw new \RuntimeException('Invalid backup method');
    }
    if ($primary_method === $backup_method) {
      throw new \RuntimeException('Primary and backup methods must be different');
    }
    if ($primary_method === 'totp' && !$totp_secret) {
      throw new \RuntimeException('TOTP secret required for TOTP method');
    }

    $data = [
      'userid'         => $userid,
      'primary_method' => $primary_method,
      'backup_method'  => $backup_method,
      'totp_secret'    => $primary_method === 'totp' ? $totp_secret : null,
      'backup_email'   => $backup_email,
      'configured_at'  => (int) date('Ymd'),
    ];

    if (self::hasConfigured2FA($db, $userid)) {
      TwoFactorModel::update($db, $data);
    } else {
      TwoFactorModel::insert($db, $data);
    }

    // 2FA configurado: ya no hay plazo de gracia.
    User::setdeadline2fa($db, (int) $userid, null);
  }

  public static function hasConfigured2FA($db, $userid)
  {
    return TwoFactorModel::hasUser($db, $userid);
  }

  public static function isInGracePeriod($db, $userid)
  {
    $user = User::get($db, $userid);

    if (!$user || !$user['deadline2fa']) {
      return false;
    }

    $today = (int) date('Ymd');
    return (int) $user['deadline2fa'] >= $today;
  }

  public static function getDeadline2FA($db, $userid)
  {
    $user = User::get($db, $userid);
    return $user ? $user['deadline2fa'] : null;
  }

  /** Borra el 2FA del usuario y le da TWOFA_GRACE_DAYS días para volver a configurarlo. */
  public static function reset2FA($db, $userid)
  {
    TwoFactorModel::delete($db, $userid);
    User::setdeadline2fa($db, (int) $userid, self::gracedeadline());
  }

  /** Fecha yyyymmdd límite para configurar 2FA a partir de hoy. */
  public static function gracedeadline(): int
  {
    $days = defined('TWOFA_GRACE_DAYS') ? (int) TWOFA_GRACE_DAYS : 7;
    return (int) date('Ymd', strtotime('+' . $days . ' days'));
  }

  public static function validate2FA($db, $userid, $code, $primary_method)
  {
    if (!$primary_method) {
      throw new \RuntimeException('Primary method not configured');
    }

    if ($primary_method === 'totp') {
      $twofa = TwoFactorModel::get($db, $userid);
      if (!$twofa || !$twofa['totp_secret']) {
        return false;
      }
      return self::validateTOTP($twofa['totp_secret'], $code);
    } elseif ($primary_method === 'email') {
      return self::validateEmailCode($db, $userid, $code);
    }

    return false;
  }

  /**
   * Fase 2 del login con límite de intentos por usuario (LoginThrottle, clave twofakey): 5 fallos en
   * 15 minutos ⇒ LoginThrottledException, aunque se vuelva a pasar la fase de contraseña. El intento se
   * registra antes de comprobar el código (como verifycredentials) y un código correcto borra los fallos.
   */
  public static function verify2fa($db, int $userid, string $code, string $method, string $ip, ?int $now = null): bool
  {
    $now = $now ?? time();
    $key = LoginThrottle::twofakey($userid);
    $recent = LoginIntento::recent($db, $key, '', $now - LoginThrottle::WINDOW);
    if (LoginThrottle::isblocked($recent, $now, $key)) {
      throw new LoginThrottledException(self::THROTTLEMESSAGE);
    }
    LoginIntento::record($db, $key, $ip, $now);
    if (!self::validate2FA($db, $userid, $code, $method)) return false;
    LoginIntento::clearemail($db, $key);
    return true;
  }

  /** Datos que la Screen necesita para la fase 2 (sin exponer Models a la Screen). */
  public static function pendinguser($db, $userid): array
  {
    return [
      'user'  => User::get($db, $userid) ?: null,
      'twofa' => TwoFactorModel::get($db, $userid) ?: null,
    ];
  }
}
