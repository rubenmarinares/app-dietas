<?php declare(strict_types=1);
/**
 * Crea un usuario administrador (tipo 1 "Administrador", sembrado por 000_base_schema.sql).
 *
 *   php tools/create_admin.php <email> "<Nombre>" ["<Apellidos>"]
 *
 * La contraseña se pide por consola (nunca como argumento: quedaría en el historial).
 * Pasa por UserDomain → User::insert, así que se guarda con password_hash().
 * El usuario recibe TWOFA_GRACE_DAYS días para configurar 2FA desde "Mi perfil".
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';
require $root . '/src/Core/functions.php';
require $root . '/src/Config/constants.php';
date_default_timezone_set(APP_TIMEZONE);
require $root . '/src/dbschema.php';
$GLOBALS['dbschema'] = $dbschema;

$email = trim($argv[1] ?? '');
$name = trim($argv[2] ?? '');
$surname = trim($argv[3] ?? '');
if ($email === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php tools/create_admin.php <email> \"<Nombre>\" [\"<Apellidos>\"]\n");
    exit(1);
}

/** Lee una línea sin eco cuando el terminal lo permite. */
function promptsecret(string $label): string
{
    fwrite(STDOUT, $label);
    $hide = DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('stty -g 2>/dev/null');
    if ($hide) shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($hide) { shell_exec('stty echo'); fwrite(STDOUT, PHP_EOL); }
    return $value;
}

$password = promptsecret('Contraseña (mín. 10 caracteres): ');
if (strlen($password) < 10) {
    fwrite(STDERR, "La contraseña debe tener al menos 10 caracteres.\n");
    exit(1);
}
if (promptsecret('Repite la contraseña: ') !== $password) {
    fwrite(STDERR, "Las contraseñas no coinciden.\n");
    exit(1);
}

$app = (object) ['db' => \App\Core\Database::getInstance()];

if (\App\Domain\UserDomain::list($app, ['email' => $email])) {
    fwrite(STDERR, "Ya existe un usuario con el email $email.\n");
    exit(1);
}

$id = \App\Domain\UserDomain::save($app, [
    'iduser' => 0,
    'name' => $name,
    'surname' => $surname,
    'email' => $email,
    'password' => $password,
    'status' => 1,
    'typeuserid' => 1,
]);

if (!$id) {
    fwrite(STDERR, "No se pudo crear el usuario (¿migraciones aplicadas? php migrations/apply.php).\n");
    exit(1);
}
echo "Administrador creado (iduser=$id). Entra en " . APP_PATH . "login y configura 2FA desde \"Mi perfil\".\n";
