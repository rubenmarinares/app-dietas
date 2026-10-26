<?php declare(strict_types=1);

namespace Tools\Deploy;

/**
 * Calcula qué ficheros subir/borrar a partir de `git diff --name-status -M`.
 * Lógica pura (sin git ni FTP) para poder testearla.
 */
class DeployPlan
{
    /** Cambios fuera de los includes que requieren una acción manual en el servidor. */
    private const MANUAL_WARNINGS = [
        'migrations/'   => 'Hay migraciones nuevas o modificadas: ejecuta php migrations/apply.php contra producción.',
        'composer.lock' => 'composer.lock ha cambiado: hay que actualizar vendor/ en el servidor manualmente.',
    ];

    /**
     * @param string   $namestatus salida de `git diff --name-status -M <base> <head>`
     * @param string[] $includes   rutas desplegables: directorios terminados en '/' o ficheros exactos
     * @return array{upload: string[], delete: string[], warnings: string[]}
     */
    public static function build(string $namestatus, array $includes): array
    {
        $upload = [];
        $delete = [];
        $warnings = [];

        foreach (preg_split('/\R/', trim($namestatus)) as $line) {
            if ($line === '') continue;
            $parts = explode("\t", $line);
            $status = $parts[0][0];

            if ($status === 'R' || $status === 'C') {
                [$oldpath, $newpath] = [$parts[1], $parts[2]];
                if ($status === 'R' && self::isdeployable($oldpath, $includes)) $delete[] = $oldpath;
                $touched = [$oldpath, $newpath];
                if (self::isdeployable($newpath, $includes)) $upload[] = $newpath;
            } else {
                $path = $parts[1];
                $touched = [$path];
                if (self::isdeployable($path, $includes)) {
                    if ($status === 'D') $delete[] = $path;
                    else $upload[] = $path;
                }
            }

            foreach (self::MANUAL_WARNINGS as $prefix => $message) {
                foreach ($touched as $path) {
                    if (str_starts_with($path, $prefix) && !in_array($message, $warnings, true)) {
                        $warnings[] = $message;
                    }
                }
            }
        }

        return ['upload' => $upload, 'delete' => $delete, 'warnings' => $warnings];
    }

    public static function isdeployable(string $path, array $includes): bool
    {
        if (strtolower(substr($path, -3)) === '.md') return false;
        foreach ($includes as $include) {
            // "src/" es una carpeta; "src" sin barra vale como fichero exacto o como carpeta (FTP_PATHS=src,public).
            $folder = rtrim($include, '/') . '/';
            if ($path === $include || str_starts_with($path, $folder)) {
                return true;
            }
        }
        return false;
    }
}
