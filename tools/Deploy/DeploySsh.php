<?php declare(strict_types=1);

namespace Tools\Deploy;

/**
 * Comandos remotos del despliegue por SSH (tools/deploy_ssh.php). Lógica pura (sin ssh ni git) para poder
 * testearla: rutas siempre relativas y entre comillas simples de shell.
 */
class DeploySsh
{
    /** Ruta relativa segura dentro del docroot, o InvalidArgumentException. */
    public static function safepath(string $path): string
    {
        if ($path === '' || $path[0] === '/' || $path[0] === '-' || preg_match('/[\x00-\x1f]/', $path)
            || in_array('..', explode('/', $path), true)) {
            throw new \InvalidArgumentException('Ruta no permitida en el despliegue: ' . json_encode($path));
        }
        return $path;
    }

    /** Comillas simples de shell POSIX (la del servidor), independientes del sistema local. */
    public static function q(string $s): string
    {
        return "'" . str_replace("'", "'\\''", $s) . "'";
    }

    public static function extractcommand(string $root, string $archive): string
    {
        return 'mkdir -p ' . self::q($root) . ' && tar -xzf ' . self::q($archive) . ' -C ' . self::q($root) . ' && rm -f ' . self::q($archive);
    }

    /** rm de los ficheros borrados en git ('' si no hay ninguno). */
    public static function deletecommand(string $root, array $paths): string
    {
        if (!$paths) return '';
        return 'cd ' . self::q($root) . ' && rm -f -- ' . implode(' ', array_map(fn($p) => self::q(self::safepath($p)), $paths));
    }

    public static function readmarkercommand(string $root): string
    {
        return 'cat ' . self::q($root . '/.deployed_commit') . ' 2>/dev/null';
    }

    public static function writemarkercommand(string $root, string $commit): string
    {
        return "printf '%s' " . self::q($commit) . ' > ' . self::q($root . '/.deployed_commit');
    }

    /** Argumentos de ssh (sin el comando): puerto, clave y BatchMode (nunca pide contraseña). */
    public static function sshargs(array $env): array
    {
        return array_merge(self::common($env, '-p'), [($env['SSH_USER'] ?? '') . '@' . ($env['SSH_HOST'] ?? '')]);
    }

    /** Argumentos de scp (el puerto es -P). */
    public static function scpargs(array $env): array
    {
        return self::common($env, '-P');
    }

    private static function common(array $env, string $portflag): array
    {
        $args = [];
        if (($env['SSH_PORT'] ?? '') !== '') array_push($args, $portflag, (string) $env['SSH_PORT']);
        if (($env['SSH_KEY'] ?? '') !== '') array_push($args, '-i', (string) $env['SSH_KEY']);
        return array_merge($args, ['-o', 'BatchMode=yes']);
    }
}
