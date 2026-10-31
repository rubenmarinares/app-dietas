<?php declare(strict_types=1);

namespace App\Core;

/**
 * Paginación pura (sin BBDD): cálculo de la página actual, URLs del paginador y lectura
 * tolerante de `?pag=`. Las páginas empiezan en 1, igual que ModelBase::searchpage;
 * la página 1 no lleva `pag` en la URL y la 2 es `?pag=2`.
 */
final class Pagination
{
    /**
     * `current` se acota a [1, pages]; siempre hay al menos una página.
     * `prev` es null en la página 1 y `next` es null en la última.
     * @return array{current:int,pages:int,prev:?int,next:?int}
     */
    static public function calc(int $total, int $perpage, int $current): array
    {
        $perpage = max(1, $perpage);
        $pages = max(1, (int) ceil(max(0, $total) / $perpage));
        $current = min(max(1, $current), $pages);
        return [
            'current' => $current,
            'pages' => $pages,
            'prev' => $current > 1 ? $current - 1 : null,
            'next' => $current < $pages ? $current + 1 : null,
        ];
    }

    /**
     * calc() + URLs del paginador: prevurl, nexturl, currenturl y `links` (ventana de 7 páginas
     * alrededor de la actual: number = la página, url, current). La página 1 no lleva `pag`;
     * $params se añaden antes de `pag` (valor '' = solo la clave: "?historico").
     */
    static public function links(string $path, array $pagination, array $params = []): array
    {
        $url = function (int $page) use ($path, $params): string {
            $parts = [];
            foreach ($params as $key => $value) {
                $parts[] = rawurlencode((string) $key) . ((string) $value === '' ? '' : '=' . rawurlencode((string) $value));
            }
            if ($page > 1) $parts[] = 'pag=' . $page;
            return $path . ($parts ? '?' . implode('&', $parts) : '');
        };
        $window = 7;
        $current = (int) $pagination['current'];
        $total = (int) $pagination['pages'];
        $start = max(1, min($current - intdiv($window, 2), $total - $window + 1));
        $end = min($total, $start + $window - 1);
        $pages = [];
        for ($i = $start; $i <= $end; $i++) $pages[] = ['number' => $i, 'url' => $url($i), 'current' => $i === $current];
        return $pagination + [
            'prevurl' => $pagination['prev'] !== null ? $url((int) $pagination['prev']) : null,
            'nexturl' => $pagination['next'] !== null ? $url((int) $pagination['next']) : null,
            'currenturl' => $url($current),
            'links' => $pages,
        ];
    }

    /** `?pag=` tolerante: ausente, no numérico o array → 1 (calc() acota el 0, negativos y excesos). */
    static public function param(array $query): int
    {
        $pag = $query['pag'] ?? 1;
        return (is_scalar($pag) && preg_match('~^\s*-?\d{1,9}\s*$~', (string) $pag)) ? (int) $pag : 1;
    }
}
