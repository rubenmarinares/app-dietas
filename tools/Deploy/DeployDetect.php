<?php declare(strict_types=1);

namespace Tools\Deploy;

/**
 * Detecta qué commit está desplegado comparando el contenido remoto con el historial.
 * Lógica pura (sin git ni FTP) para poder testearla.
 */
class DeployDetect
{
    /** Hash del contenido ignorando diferencias CRLF/LF (subidas manuales desde Windows). */
    public static function normalizedhash(string $content): string
    {
        return sha1(str_replace("\r\n", "\n", $content));
    }

    /**
     * @param array<string, ?string> $remote  path => hash normalizado del fichero remoto (null si no existe)
     * @param array<int, array{sha: string, files: array<string, ?string>}> $commits del más nuevo al más antiguo;
     *        files: path => hash normalizado en ese commit (null si no existe en ese commit)
     * @return array<int, array{sha: string, mismatches: string[]}> ordenado por menos discrepancias y, a igualdad, más nuevo
     */
    public static function rank(array $remote, array $commits): array
    {
        $ranking = [];
        foreach ($commits as $position => $commit) {
            $mismatches = [];
            foreach ($remote as $path => $remotehash) {
                if (($commit['files'][$path] ?? null) !== $remotehash) $mismatches[] = $path;
            }
            $ranking[] = ['sha' => $commit['sha'], 'mismatches' => $mismatches, 'position' => $position];
        }
        usort($ranking, fn($a, $b) => [count($a['mismatches']), $a['position']] <=> [count($b['mismatches']), $b['position']]);
        return array_map(fn($r) => ['sha' => $r['sha'], 'mismatches' => $r['mismatches']], $ranking);
    }
}
