<?php declare(strict_types=1);

// CLI: php tools/deploy.php [--dry-run] [--since=<commit>] [--delete] [--yes]
//
// Sube por FTP/FTPS los ficheros que han cambiado entre el último commit desplegado
// y HEAD. El contenido se lee del commit (git cat-file), no de la copia de trabajo.
// El último commit desplegado se guarda en el servidor, en <FTP_ROOT>/.deployed_commit.
//
// Configuración en .env (raíz del proyecto, ignorado por git):
//   FTP_HOST=ftp.ejemplo.com
//   FTP_PORT=21
//   FTP_USER=usuario
//   FTP_PASSWORD=secreto
//   FTP_ROOT=/httpdocs            (carpeta remota equivalente a la raíz del repo)
//   FTP_SSL=1                     (1 = FTPS explícito, 0 = FTP sin cifrar)
//   FTP_PATHS=src/,public/,.htaccess
//
// Opciones:
//   --dry-run         muestra el plan y no sube nada
//   --since=<commit>  base del diff (obligatorio la primera vez, si no hay marcador remoto)
//   --delete          borra en el servidor los ficheros eliminados/renombrados en git
//   --yes             no pide confirmación
//   --detect[=N]      solo lectura: compara el servidor con los últimos N commits (40 por defecto)
//                     y dice cuál está desplegado. No sube ni escribe nada.
//   --fetch=<ruta> --out=<fichero>  solo lectura: descarga un fichero remoto
//   --paths=a,b       sube esos ficheros tal como están en HEAD (no toca el marcador)
//   --remove=a,b      borra esos ficheros en el servidor (no toca el marcador)

require __DIR__ . '/Deploy/DeployPlan.php';
require __DIR__ . '/Deploy/DeployDetect.php';

use Tools\Deploy\DeployDetect;
use Tools\Deploy\DeployPlan;

const MARKER = '.deployed_commit';

$root = realpath(__DIR__ . '/..');
chdir($root);

$options = getopt('', ['dry-run', 'since:', 'delete', 'yes', 'detect::', 'fetch:', 'out:', 'paths:', 'remove:']);
$dryrun = isset($options['dry-run']);
$dodelete = isset($options['delete']);
$assumeyes = isset($options['yes']);

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: $message\n");
    exit(1);
}

function git(string $args): string
{
    exec('git ' . $args . ' 2>&1', $output, $code);
    if ($code !== 0) fail("git $args\n" . implode("\n", $output));
    return implode("\n", $output);
}

function loadenv(string $file): array
{
    $env = [];
    $raw = is_file($file) ? (string) file_get_contents($file) : '';
    // Tolera .env guardados desde Windows: UTF-16 (PowerShell 5) y BOM UTF-8
    if (str_starts_with($raw, "\xFF\xFE")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    elseif (str_starts_with($raw, "\xFE\xFF")) $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    elseif (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim(preg_replace('/^(export|set)\s+/i', '', trim($line)));
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        $env[$k] = $v;
    }
    // Las variables de entorno reales tienen prioridad sobre el fichero
    foreach (['FTP_HOST', 'FTP_PORT', 'FTP_USER', 'FTP_PASSWORD', 'FTP_ROOT', 'FTP_SSL', 'FTP_PATHS'] as $k) {
        if (getenv($k) !== false) $env[$k] = getenv($k);
    }
    return $env;
}

function remotepath(string $ftproot, string $path): string
{
    return rtrim($ftproot, '/') . '/' . ltrim($path, '/');
}

function ensureremotedir($ftp, string $dir): void
{
    $parts = explode('/', trim($dir, '/'));
    $current = str_starts_with($dir, '/') ? '' : '.';
    foreach ($parts as $part) {
        if ($part === '') continue;
        $current .= '/' . $part;
        if (@ftp_chdir($ftp, $current)) continue;
        if (!@ftp_mkdir($ftp, $current)) fail("No se pudo crear el directorio remoto $current");
    }
    @ftp_chdir($ftp, '/');
}

/** git sin pasar por la shell (evita que cmd.exe interprete % y comillas). */
function gitraw(array $args): string
{
    $process = proc_open(array_merge(['git'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) fail('git ' . implode(' ', $args) . "\n$stderr");
    return trim($stdout);
}

/**
 * Extrae el blob exacto a un fichero temporal, sin conversión de fin de línea.
 * Con $path vacío, $commit se interpreta como el hash del blob.
 */
function exportblob(string $commit, string $path): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'deploy');
    $process = proc_open(
        ['git', 'cat-file', 'blob', $path === '' ? $commit : "$commit:$path"],
        [1 => ['file', $tmp, 'wb'], 2 => ['pipe', 'w']],
        $pipes
    );
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        @unlink($tmp);
        fail("git cat-file $commit:$path\n$stderr");
    }
    return $tmp;
}

// --- Configuración ---
$env = loadenv($root . '/.env');
$missing = array_filter(['FTP_HOST', 'FTP_USER', 'FTP_PASSWORD', 'FTP_ROOT'], fn($key) => ($env[$key] ?? '') === '');
if ($missing) {
    fail('Faltan en ' . $root . DIRECTORY_SEPARATOR . '.env: ' . implode(', ', $missing)
        . (is_file($root . '/.env') ? ' (el fichero existe; revisa que las claves estén escritas exactamente así, una por línea, y que el fichero esté guardado)' : ' (el fichero no existe)'));
}
$ftproot = $env['FTP_ROOT'];
$port = (int) ($env['FTP_PORT'] ?? 21);
$usessl = ($env['FTP_SSL'] ?? '1') !== '0';
$includes = array_values(array_filter(array_map('trim', explode(',', $env['FTP_PATHS'] ?? 'src/,public/,.htaccess'))));

// --- Estado de git ---
$head = git('rev-parse HEAD');
$branch = git('rev-parse --abbrev-ref HEAD');
if (git('status --porcelain') !== '') {
    echo "AVISO: hay cambios sin commitear; NO se desplegarán (solo se sube lo commiteado en HEAD).\n";
}
exec('git branch -r --contains ' . escapeshellarg($head) . ' 2>&1', $remotebranches);
if (count(array_filter($remotebranches, fn($b) => trim($b) !== '')) === 0) {
    echo "AVISO: HEAD ($branch " . substr($head, 0, 7) . ") no está pusheado a ningún remoto.\n";
}

// --- Conexión ---
function ftpconectar(array $env, int $port, bool $usessl)
{
    $ftp = $usessl ? @ftp_ssl_connect($env['FTP_HOST'], $port, 30) : @ftp_connect($env['FTP_HOST'], $port, 30);
    if (!$ftp) fail("No se pudo conectar a {$env['FTP_HOST']}:$port" . ($usessl ? ' (FTPS)' : ''));
    if (!@ftp_login($ftp, $env['FTP_USER'], $env['FTP_PASSWORD'])) fail('Login FTP incorrecto');
    ftp_pasv($ftp, true);
    return $ftp;
}

/**
 * Subida en dos partes (STOR + APPE), comprobando el tamaño final. El servidor FTPS
 * aborta ("Failure reading network stream" / 426) las transferencias de entre
 * ~65100 y 65534 bytes, sea cual sea el contenido; cada mitad queda fuera de esa franja.
 * Devuelve '' si ha ido bien o el motivo del fallo.
 */
function ftpputpartes(array $env, int $port, bool $usessl, string $remote, string $local): string
{
    $contenido = (string) file_get_contents($local);
    $mitad = intdiv(strlen($contenido), 2);
    foreach ([[0, $mitad], [$mitad, strlen($contenido) - $mitad]] as $i => [$desde, $largo]) {
        $tmp = tempnam(sys_get_temp_dir(), 'deploy');
        file_put_contents($tmp, substr($contenido, $desde, $largo));
        $ftp = ftpconectar($env, $port, $usessl);
        $ok = $i === 0 ? @ftp_put($ftp, $remote, $tmp, FTP_BINARY) : @ftp_append($ftp, $remote, $tmp, FTP_BINARY);
        $motivo = $ok ? '' : (error_get_last()['message'] ?? 'sin detalle');
        @ftp_close($ftp);
        @unlink($tmp);
        if (!$ok) return 'parte ' . ($i + 1) . ": $motivo";
    }
    $ftp = ftpconectar($env, $port, $usessl);
    $remoto = @ftp_size($ftp, $remote);
    @ftp_close($ftp);
    return $remoto === strlen($contenido) ? '' : "tamaño remoto $remoto en vez de " . strlen($contenido);
}

$ftp = ftpconectar($env, $port, $usessl);

// --- Descarga de un fichero remoto (solo lectura) ---
if (isset($options['fetch'])) {
    $out = $options['out'] ?? fail('--fetch necesita --out=<fichero local>');
    if (!@ftp_get($ftp, $out, remotepath($ftproot, $options['fetch']), FTP_BINARY)) fail("No se pudo descargar {$options['fetch']}");
    @ftp_close($ftp);
    echo "Descargado {$options['fetch']} -> $out (" . filesize($out) . " bytes)\n";
    exit(0);
}

// --- Detección (solo lectura) ---
if (array_key_exists('detect', $options)) {
    $depth = max(1, (int) ($options['detect'] ?: 40));
    $shas = array_values(array_filter(explode("\n", git("rev-list --first-parent -n $depth HEAD"))));

    // Ficheros desplegables tocados en esos commits
    $touched = array_filter(explode("\n", git("log --first-parent -n $depth --name-only --format= HEAD")));
    $files = array_values(array_unique(array_filter($touched, fn($p) => DeployPlan::isdeployable($p, $includes))));
    sort($files);
    if (!$files) fail("Ningún fichero desplegable cambió en los últimos $depth commits.");
    echo "Comparando " . count($files) . " ficheros del servidor con los últimos " . count($shas) . " commits...\n";

    // Contenido remoto
    $remote = [];
    foreach ($files as $path) {
        $tmp = tempnam(sys_get_temp_dir(), 'deploy');
        $remote[$path] = @ftp_get($ftp, $tmp, remotepath($ftproot, $path), FTP_BINARY)
            ? DeployDetect::normalizedhash((string) file_get_contents($tmp))
            : null;
        @unlink($tmp);
    }
    @ftp_close($ftp);

    // Contenido de cada fichero en cada commit (cacheado por blob)
    $blobhashes = [];
    $commits = [];
    $wanted = array_flip($files);
    foreach ($shas as $sha) {
        $filehashes = array_fill_keys($files, null);
        foreach (explode("\n", git('ls-tree -r ' . escapeshellarg($sha))) as $line) {
            if (!preg_match('/^\d+ blob ([0-9a-f]+)\t(.+)$/', $line, $m) || !isset($wanted[$m[2]])) continue;
            if (!isset($blobhashes[$m[1]])) {
                $tmp = exportblob($m[1], '');
                $blobhashes[$m[1]] = DeployDetect::normalizedhash((string) file_get_contents($tmp));
                @unlink($tmp);
            }
            $filehashes[$m[2]] = $blobhashes[$m[1]];
        }
        $commits[] = ['sha' => $sha, 'files' => $filehashes];
    }

    $ranking = DeployDetect::rank($remote, $commits);
    $best = $ranking[0];
    $subject = gitraw(['log', '-1', '--format=%h %ad %s', '--date=short', $best['sha']]);
    if (!$best['mismatches']) {
        echo "\nEl servidor coincide con: $subject\n";
        echo "Siguiente paso: php tools/deploy.php --since=" . substr($best['sha'], 0, 7) . " --dry-run\n";
        exit(0);
    }
    echo "\nNingún commit coincide del todo. El más parecido: $subject\n";
    echo "Ficheros que no cuadran (" . count($best['mismatches']) . "):\n";
    foreach ($best['mismatches'] as $path) {
        echo "  ~ $path" . ($remote[$path] === null ? ' (no existe en el servidor)' : '') . "\n";
        if ($remote[$path] === null) continue;
        // Busca en todo el historial del fichero la versión que coincide con la del servidor
        $found = null;
        $history = gitraw(['log', '--all', '--format=%H%x09%h %ad %s', '--date=short', '--', $path]);
        foreach (array_filter(explode("\n", $history)) as $entry) {
            [$sha, $label] = explode("\t", $entry, 2);
            $tree = gitraw(['ls-tree', $sha, '--', $path]);
            if (!preg_match('/^\d+ blob ([0-9a-f]+)\t/', $tree, $m)) continue;
            if (!isset($blobhashes[$m[1]])) {
                $tmp = exportblob($m[1], '');
                $blobhashes[$m[1]] = DeployDetect::normalizedhash((string) file_get_contents($tmp));
                @unlink($tmp);
            }
            if ($blobhashes[$m[1]] === $remote[$path]) { $found = $label; break; }
        }
        echo '      servidor = ' . ($found ?? 'NINGUNA versión de git (probable cambio manual en el servidor)') . "\n";
    }
    echo "\nPuede ser un deploy parcial o cambios hechos directamente en el servidor. Si el servidor es más antiguo, prueba --detect=100.\n";
    exit(0);
}

// --- Subida/borrado de ficheros concretos (no toca el marcador) ---
if (isset($options['paths']) || isset($options['remove'])) {
    $split = fn($value) => array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    $uploads = $split($options['paths'] ?? '');
    $removes = $split($options['remove'] ?? '');
    foreach ($uploads as $path) {
        if (!DeployPlan::isdeployable($path, $includes)) fail("$path no está dentro de FTP_PATHS o es .md");
        exec('git cat-file -e ' . escapeshellarg("$head:$path") . ' 2>&1', $ignored, $existscode);
        if ($existscode !== 0) fail("$path no existe en HEAD");
    }
    echo "\nFicheros concretos desde " . substr($head, 0, 7) . " a {$env['FTP_HOST']}:$ftproot\n";
    foreach ($uploads as $path) echo "  + $path\n";
    foreach ($removes as $path) echo "  - $path (borrar en el servidor)\n";
    if ($dryrun) { echo "\n--dry-run: no se ha tocado nada.\n"; exit(0); }
    if (!$assumeyes) {
        echo "\n¿Continuar? [s/N] ";
        if (strtolower(trim((string) fgets(STDIN))) !== 's') { echo "Cancelado.\n"; exit(1); }
    }
    foreach ($uploads as $path) {
        $remote = remotepath($ftproot, $path);
        ensureremotedir($ftp, dirname($remote));
        $local = exportblob($head, $path);
        $ok = @ftp_put($ftp, $remote, $local, FTP_BINARY);
        @unlink($local);
        if (!$ok) fail("Fallo al subir $path");
        echo "  subido $path\n";
    }
    foreach ($removes as $path) {
        if (@ftp_delete($ftp, remotepath($ftproot, $path))) echo "  borrado $path\n";
        else echo "  AVISO: no se pudo borrar $path (¿ya no existía?)\n";
    }
    @ftp_close($ftp);
    echo "\nHecho.\n";
    exit(0);
}

// --- Base del diff ---
if (isset($options['since'])) {
    $base = git('rev-parse ' . escapeshellarg($options['since'] . '^{commit}'));
} else {
    $tmp = tempnam(sys_get_temp_dir(), 'deploy');
    if (!@ftp_get($ftp, $tmp, remotepath($ftproot, MARKER), FTP_BINARY)) {
        @unlink($tmp);
        fail('No hay ' . MARKER . ' en el servidor. Primer deploy: indica la base con --since=<commit ya desplegado>.');
    }
    $base = trim((string) file_get_contents($tmp));
    @unlink($tmp);
    git('cat-file -e ' . escapeshellarg($base . '^{commit}'));
}

if ($base === $head) {
    echo "Nada que desplegar: el servidor ya está en " . substr($head, 0, 7) . ".\n";
    exit(0);
}
exec('git merge-base --is-ancestor ' . escapeshellarg($base) . ' ' . escapeshellarg($head), $ignored, $ancestorcode);
if ($ancestorcode !== 0) {
    fail('El commit desplegado (' . substr($base, 0, 7) . ') no es ancestro de HEAD. Revisa la rama o usa --since.');
}

// --- Plan ---
$plan = DeployPlan::build(git('diff --name-status -M ' . escapeshellarg($base) . ' ' . escapeshellarg($head)), $includes);

echo "\nDeploy " . substr($base, 0, 7) . ' -> ' . substr($head, 0, 7) . " ($branch) a {$env['FTP_HOST']}:$ftproot\n";
echo "\nSubir (" . count($plan['upload']) . "):\n";
foreach ($plan['upload'] as $path) echo "  + $path\n";
if ($plan['delete']) {
    echo "\n" . ($dodelete ? 'Borrar' : 'Eliminados en git (NO se borran sin --delete)') . ' (' . count($plan['delete']) . "):\n";
    foreach ($plan['delete'] as $path) echo "  - $path\n";
}
foreach ($plan['warnings'] as $warning) echo "\nAVISO: $warning\n";

if ($dryrun) {
    echo "\n--dry-run: no se ha subido nada.\n";
    exit(0);
}
if (!$plan['upload'] && !($dodelete && $plan['delete'])) {
    echo "\nNingún fichero desplegable; se actualiza solo el marcador.\n";
} elseif (!$assumeyes) {
    echo "\n¿Continuar? [s/N] ";
    if (strtolower(trim((string) fgets(STDIN))) !== 's') {
        echo "Cancelado.\n";
        exit(1);
    }
}

// --- Subida ---
foreach ($plan['upload'] as $path) {
    $remote = remotepath($ftproot, $path);
    ensureremotedir($ftp, dirname($remote));
    $local = exportblob($head, $path);
    $ok = @ftp_put($ftp, $remote, $local, FTP_BINARY);
    if (!$ok) {
        $motivo = error_get_last()['message'] ?? 'sin detalle';
        @ftp_close($ftp);  // la conexión FTP queda inservible tras el fallo
        $error = ftpputpartes($env, $port, $usessl, $remote, $local);
        $ftp = ftpconectar($env, $port, $usessl);
        if ($error !== '') {
            @unlink($local);
            fail("Fallo al subir $path ($motivo; reintento en dos partes: $error). El marcador NO se ha actualizado; vuelve a lanzar el deploy.");
        }
        echo "  subido $path (en dos partes tras: $motivo)\n";
        @unlink($local);
        continue;
    }
    @unlink($local);
    echo "  subido $path\n";
}
if ($dodelete) {
    foreach ($plan['delete'] as $path) {
        if (@ftp_delete($ftp, remotepath($ftproot, $path))) echo "  borrado $path\n";
        else echo "  AVISO: no se pudo borrar $path (¿ya no existía?)\n";
    }
}

// --- Marcador ---
$tmp = tempnam(sys_get_temp_dir(), 'deploy');
file_put_contents($tmp, $head . "\n");
$ok = @ftp_put($ftp, remotepath($ftproot, MARKER), $tmp, FTP_BINARY);
@unlink($tmp);
ftp_close($ftp);
if (!$ok) fail('Ficheros subidos, pero no se pudo escribir ' . MARKER . '. Usa --since la próxima vez.');

echo "\nDeploy completado: servidor en " . substr($head, 0, 7) . ".\n";
