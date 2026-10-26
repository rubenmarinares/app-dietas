<?php declare(strict_types=1);

// CLI: php tools/deploy_ssh.php [--dry-run] [--since=<commit>] [--delete] [--yes]
//
// Despliegue por SSH (recomendado frente a deploy.php por FTP): mismo plan (DeployPlan, git diff entre el commit
// desplegado y HEAD, limitado a DEPLOY_PATHS) y mismo marcador <SSH_ROOT>/.deployed_commit, pero en vez de subir
// fichero a fichero empaqueta los cambios en un .tar.gz (contenido exacto del commit), lo sube con scp y lo
// descomprime con ssh: segundos en vez de minutos u horas en hostings con FTPS lento o limitado a una sesión.
//
// Configuración en .env (o variables de entorno):
//   SSH_HOST=example.org
//   SSH_USER=usuario
//   SSH_PORT=22                 (opcional)
//   SSH_KEY=C:/Users/yo/.ssh/id (opcional: clave privada; la contraseña nunca se usa, BatchMode=yes)
//   SSH_ROOT=www                (carpeta remota equivalente a la raíz del repo, relativa a la home)
//   SSH_PHP=/usr/local/bin/php85 (opcional: PHP de consola del servidor para comprobar la sintaxis tras subir)
//   DEPLOY_PATHS=.htaccess,public,src,migrations,tools,composer.json,composer.lock  (si falta, FTP_PATHS)
// Nunca sube .md ni vendor/ (composer install --no-dev aparte) ni el .env.

require __DIR__ . '/Deploy/DeployPlan.php';
require __DIR__ . '/Deploy/DeploySsh.php';

use Tools\Deploy\DeployPlan;
use Tools\Deploy\DeploySsh;

$root = realpath(__DIR__ . '/..');
chdir($root);
$options = getopt('', ['dry-run', 'since:', 'delete', 'yes']);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

/** Ejecuta un programa sin shell local (sin problemas de comillas en Windows). Devuelve [código, salida]. */
function run(array $cmd, bool $quiet = false): array
{
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) fail('No se pudo ejecutar ' . $cmd[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 && !$quiet) fail(implode(' ', array_slice($cmd, 0, 2)) . " … (código $code)\n" . trim($err));
    return [$code, trim((string) $out)];
}

function git(array $args): string
{
    return run(array_merge(['git'], $args))[1];
}

function loadenv(string $file): array
{
    $env = [];
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim(preg_replace('/^(export|set)\s+/i', '', trim($line)));
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        $env[$k] = $v;
    }
    foreach (['SSH_HOST', 'SSH_USER', 'SSH_PORT', 'SSH_KEY', 'SSH_ROOT', 'SSH_PHP', 'DEPLOY_PATHS', 'FTP_PATHS'] as $k) {
        if (getenv($k) !== false) $env[$k] = getenv($k);
    }
    return $env;
}

$env = loadenv($root . '/.env');
foreach (['SSH_HOST', 'SSH_USER', 'SSH_ROOT'] as $k) if (($env[$k] ?? '') === '') fail("Falta $k en .env.");
$remoteroot = trim($env['SSH_ROOT'], '/');
$includes = array_values(array_filter(array_map('trim', explode(',', $env['DEPLOY_PATHS'] ?? $env['FTP_PATHS'] ?? 'src/,public/,.htaccess'))));
$ssh = fn(string $command, bool $quiet = false) => run(array_merge(['ssh'], DeploySsh::sshargs($env), [$command]), $quiet);

$head = git(['rev-parse', 'HEAD']);
$branch = git(['rev-parse', '--abbrev-ref', 'HEAD']);
if (git(['status', '--porcelain']) !== '') echo "AVISO: hay cambios sin commitear; NO se desplegarán (solo lo commiteado en HEAD).\n";

// --- Base del diff ---
if (isset($options['since'])) {
    $base = git(['rev-parse', $options['since'] . '^{commit}']);
} else {
    [$code, $marker] = $ssh(DeploySsh::readmarkercommand($remoteroot), true);
    if ($code !== 0 || !preg_match('/^[0-9a-f]{40}$/', $marker)) {
        fail('No hay .deployed_commit en el servidor. Primer deploy: indica la base con --since=<commit ya desplegado>.');
    }
    $base = $marker;
    git(['cat-file', '-e', $base . '^{commit}']);
}
if ($base === $head) { echo 'Nada que desplegar: el servidor ya está en ' . substr($head, 0, 7) . ".\n"; exit(0); }
[$ancestor] = run(['git', 'merge-base', '--is-ancestor', $base, $head], true);
if ($ancestor !== 0) fail('El commit desplegado (' . substr($base, 0, 7) . ') no es ancestro de HEAD. Revisa la rama o usa --since.');

// --- Plan ---
$plan = DeployPlan::build(git(['diff', '--name-status', '-M', $base, $head]), $includes);
foreach (array_merge($plan['upload'], $plan['delete']) as $p) DeploySsh::safepath($p);
echo "\nDeploy " . substr($base, 0, 7) . ' -> ' . substr($head, 0, 7) . " ($branch) a {$env['SSH_USER']}@{$env['SSH_HOST']}:$remoteroot\n";
echo "\nSubir (" . count($plan['upload']) . "):\n";
foreach ($plan['upload'] as $p) echo "  + $p\n";
if ($plan['delete']) {
    echo "\n" . (isset($options['delete']) ? 'Borrar' : 'Eliminados en git (NO se borran sin --delete)') . ' (' . count($plan['delete']) . "):\n";
    foreach ($plan['delete'] as $p) echo "  - $p\n";
}
foreach ($plan['warnings'] as $w) echo "\nAVISO: $w\n";
if (isset($options['dry-run'])) { echo "\n--dry-run: no se ha subido nada.\n"; exit(0); }
if (!isset($options['yes'])) {
    echo "\n¿Continuar? [s/N] ";
    if (strtolower(trim((string) fgets(STDIN))) !== 's') { echo "Cancelado.\n"; exit(1); }
}

// --- Paquete con el contenido exacto del commit (sin conversión de fin de línea) ---
if ($plan['upload']) {
    $tar = sys_get_temp_dir() . '/deploy-' . substr($head, 0, 12) . '.tar';
    @unlink($tar);
    @unlink("$tar.gz");
    // Blob exacto de cada fichero (sin trim ni conversión de fin de línea).
    $archive = new PharData($tar);
    foreach ($plan['upload'] as $p) {
        $process = proc_open(['git', 'cat-file', 'blob', "$head:$p"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $blob = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) fail("git cat-file $head:$p");
        $archive->addFromString($p, (string) $blob);
    }
    $archive->compress(Phar::GZ);
    unset($archive);
    $remotefile = '.deploy-' . substr($head, 0, 12) . '.tar.gz';
    run(array_merge(['scp'], DeploySsh::scpargs($env), ["$tar.gz", "{$env['SSH_USER']}@{$env['SSH_HOST']}:$remotefile"]));
    $ssh(DeploySsh::extractcommand($remoteroot, $remotefile));
    @unlink($tar);
    @unlink("$tar.gz");
    echo "\nSubidos " . count($plan['upload']) . " ficheros.\n";
}
if (isset($options['delete']) && $plan['delete']) {
    $ssh(DeploySsh::deletecommand($remoteroot, $plan['delete']));
    echo 'Borrados ' . count($plan['delete']) . " ficheros.\n";
}

// --- Sintaxis con el PHP del servidor (opcional) ---
$php = array_values(array_filter($plan['upload'], fn($p) => str_ends_with($p, '.php')));
if (($env['SSH_PHP'] ?? '') !== '' && $php) {
    $cmd = 'cd ' . DeploySsh::q($remoteroot) . ' && for f in ' . implode(' ', array_map([DeploySsh::class, 'q'], $php))
        . '; do ' . DeploySsh::q($env['SSH_PHP']) . ' -l "$f" >/dev/null 2>&1 || echo "ERROR DE SINTAXIS: $f"; done';
    [, $lint] = $ssh($cmd, true);
    if ($lint !== '') fail("Ficheros subidos con errores de sintaxis en el servidor (el marcador NO se actualiza):\n$lint");
}

$ssh(DeploySsh::writemarkercommand($remoteroot, $head));
echo "Deploy completado: servidor en " . substr($head, 0, 7) . ".\n";
