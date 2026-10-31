<?php declare(strict_types=1);

namespace App\Core;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Descarga de una tabla en .csv o .xlsx (la contrapartida de TabularReader).
 *
 *     $filas = TabularExport::send('xlsx', 'asistentes-' . date('Ymd'), [
 *         'email' => 'Email',
 *         'importe' => ['label' => 'Importe', 'width' => 14, 'format' => '#,##0.00'],
 *     ], $generador);
 *     TabularExport::log($iduser, 'asistentes', 'xlsx', $filas);
 *
 * Columnas: clave de la fila => etiqueta, o => ['label', 'width', 'format'] (`width` y `format`, código de formato
 * numérico de Excel, solo afectan al .xlsx). Solo salen las columnas declaradas: una clave de más en la fila (un
 * `password`) nunca se exporta. Filas: cualquier iterable de arrays u objetos; una clave ausente sale vacía.
 *
 * Números: solo un int/float de PHP es número (celda numérica en .xlsx, sin apóstrofo y con coma decimal en .csv). Una
 * cadena es siempre texto, aunque parezca un número: así un teléfono o un código «007» no se alteran. OJO: `Core\db` usa
 * STRINGIFY_FETCHES, todo llega como cadena; para tener números de verdad, el llamante convierte (`(float) $f['importe']`).
 * Fechas: el llamante las pasa ya como texto («31/10/2026»).
 *
 * Fórmulas: en .xlsx toda celda de texto se escribe con tipo explícito de cadena, sin adivinar por el valor ni pasar por
 * el value binder, así que «=1+1» queda como texto literal. En .csv no hay tipos: csvcell antepone un apóstrofo.
 * Límite conocido del .csv (la regla del CMS, para conservar los teléfonos): un «+» seguido de cifra no se protege, tampoco
 * si detrás hay más que un teléfono («+1+1»).
 *
 * Memoria: el .csv va fila a fila por php://output y no acumula. El .xlsx se monta ENTERO en memoria. Medido con 10
 * columnas de texto corto (PHP 8.2, PhpSpreadsheet 1.30, CLI): 10.000 filas ≈ 100 MB de pico, 50.000 ≈ 366 MB; con
 * memory_limit de 128M, 12.000 filas caben y 15.000 dan error fatal. Para volcados grandes, .csv. Un .csv que falla a
 * medias deja la descarga truncada (las cabeceras ya salieron); el .xlsx se monta antes de enviar cabeceras, así que una
 * excepción no llega a descargarse (un error fatal de memoria no se captura y puede dejar el temporal sin borrar).
 *
 * Permisos: esta clase no comprueba nada. Una ruta que exporta datos personales debe tener su permiso (y registrarse con
 * log); si es GET, 'perm' explícito en la ruta y su alta en los tests de rutas.
 */
final class TabularExport
{
    public const FORMATS = ['csv', 'xlsx'];
    private const CONTENTTYPES = [
        'csv' => 'text/csv; charset=UTF-8',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    private const BOM = "\xEF\xBB\xBF";
    private const FLUSHEVERY = 500;
    private const MAXNAME = 150;

    /**
     * Envía la descarga al navegador y devuelve las filas escritas. Limpia los búferes de salida y cierra la sesión
     * (el volcado puede tardar: no debe bloquearla; lo que se escriba después en la sesión se pierde).
     * @throws \InvalidArgumentException formato desconocido o sin columnas (antes de enviar nada)
     */
    static public function send(string $format, string $filename, array $columns, iterable $rows): int
    {
        $format = self::format($format);
        $columns = self::columns($columns);
        @set_time_limit(300);
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        if ($format === 'csv') {
            self::startresponse(self::responseheaders($format, $filename));
            return self::writecsv('php://output', $columns, $rows);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'tex');
        if ($tmp === false) throw new \RuntimeException('No se pudo crear el temporal de la exportación.');
        try {
            $n = self::writexlsx($tmp, $columns, $rows);
            self::startresponse([...self::responseheaders($format, $filename), 'Content-Length: ' . filesize($tmp)]);
            readfile($tmp);
            return $n;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Escribe la tabla en una ruta (o `php://output`), sin cabeceras HTTP. Devuelve las filas escritas.
     * @throws \InvalidArgumentException formato desconocido o sin columnas
     */
    static public function write(string $format, string $target, array $columns, iterable $rows): int
    {
        $format = self::format($format);
        $columns = self::columns($columns);
        return $format === 'csv' ? self::writecsv($target, $columns, $rows) : self::writexlsx($target, $columns, $rows);
    }

    /** Nombre seguro para Content-Disposition: solo ASCII sin separadores (un acento pasa a «-»), con la extensión del formato. */
    static public function filename(string $name, string $format): string
    {
        $format = self::format($format);
        $name = preg_replace('~\.(' . implode('|', self::FORMATS) . ')$~i', '', trim($name));
        $name = (string) preg_replace('~[^A-Za-z0-9._-]+~', '-', (string) $name);
        $name = trim(substr((string) preg_replace('~-{2,}~', '-', $name), 0, self::MAXNAME), '.-_');
        return ($name === '' ? 'export' : $name) . '.' . $format;
    }

    /** @return string[] cabeceras HTTP de la descarga */
    static public function responseheaders(string $format, string $filename): array
    {
        $format = self::format($format);
        return [
            'Content-Type: ' . self::CONTENTTYPES[$format],
            'Content-Disposition: attachment; filename="' . self::filename($filename, $format) . '"',
            'Cache-Control: private, no-store',
            'X-Content-Type-Options: nosniff',
        ];
    }

    /**
     * Celda CSV a salvo de fórmulas: apóstrofo delante de `=`, `-`, `@`, tabulador y `+` (salvo `+` seguido de cifra, para
     * los teléfonos); saltos de línea → espacio. Un int/float de PHP no puede ser una fórmula: va sin apóstrofo, con coma
     * decimal (el `;` y la coma son lo que espera Excel en español; con punto, «1.5» se abre como fecha).
     */
    static public function csvcell($value): string
    {
        if (self::isnumber($value)) return str_replace('.', ',', (string) $value);
        $value = str_replace(["\r\n", "\r", "\n"], ' ', self::text($value));
        if ($value !== '' && (strpbrk($value[0], "=-@\t") !== false || ($value[0] === '+' && !ctype_digit(substr($value, 1, 1))))) $value = "'" . $value;
        return $value;
    }

    /** Línea CSV (separador `;`, comillas dobles cuando hace falta, fin de línea CRLF para Excel). */
    static public function csvline(array $values): string
    {
        $cells = [];
        foreach ($values as $v) {
            $v = self::csvcell($v);
            $cells[] = preg_match('~[;"\s]~', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }
        return implode(';', $cells) . "\r\n";
    }

    /**
     * Línea del registro de exportaciones (pura): fecha, etiqueta, formato, id del usuario y filas. Sin datos personales:
     * la etiqueta se reduce a caracteres seguros y los valores de los filtros no se anotan nunca.
     */
    static public function logline(string $date, ?int $iduser, string $label, string $format, int $rows): string
    {
        $label = (string) preg_replace('~[^A-Za-z0-9._-]+~', '-', $label);
        return '[' . $date . '] exportar=' . $label . ' formato=' . $format . ' iduser=' . ($iduser ?? '-') . ' filas=' . $rows . "\n";
    }

    /** Fichero del registro (.htaccess sirve 404 en /storage/). */
    static public function logfile(): string
    {
        return dirname(__DIR__, 2) . '/storage/exports.log';
    }

    /** Anota una exportación en storage/exports.log. Nunca lanza (un fallo va a error_log). */
    static public function log(?int $iduser, string $label, string $format, int $rows, string $file = ''): void
    {
        try {
            $file = $file !== '' ? $file : self::logfile();
            if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
            if (@file_put_contents($file, self::logline(date('Y-m-d H:i:s'), $iduser, $label, $format, $rows), FILE_APPEND | LOCK_EX) === false) {
                error_log('[TabularExport] no se pudo escribir exports.log');
            }
        } catch (\Throwable $e) {
            error_log('[TabularExport] exports.log: ' . get_class($e));
        }
    }

    // ---- auxiliares ----

    /** @throws \InvalidArgumentException */
    static private function format(string $format): string
    {
        $lower = strtolower($format);
        if (!in_array($lower, self::FORMATS, true)) throw new \InvalidArgumentException('Formato de exportación no admitido: ' . $format);
        return $lower;
    }

    /**
     * @return list<array{key: int|string, label: string, width: ?float, format: ?string}>
     * @throws \InvalidArgumentException sin columnas
     */
    static private function columns(array $columns): array
    {
        if (!$columns) throw new \InvalidArgumentException('La exportación necesita al menos una columna.');
        $out = [];
        foreach ($columns as $key => $def) {
            $def = is_array($def) ? $def : ['label' => $def];
            $out[] = [
                'key' => $key,
                'label' => (string) ($def['label'] ?? $key),
                'width' => isset($def['width']) ? (float) $def['width'] : null,
                'format' => isset($def['format']) ? (string) $def['format'] : null,
            ];
        }
        return $out;
    }

    /** @return list<mixed> valores de la fila, solo los de las columnas declaradas y en su orden */
    static private function rowvalues(array $columns, $row): array
    {
        $row = (array) $row;
        return array_map(static fn($c) => $row[$c['key']] ?? '', $columns);
    }

    static private function isnumber($value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /** Valor como texto: lo que no es escalar (o un float NAN/INF) sale vacío. */
    static private function text($value): string
    {
        return is_scalar($value) && (!is_float($value) || is_finite($value)) ? (string) $value : '';
    }

    static private function startresponse(array $headers): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        foreach ($headers as $h) header($h);
    }

    static private function writecsv(string $target, array $columns, iterable $rows): int
    {
        $out = fopen($target, 'wb');
        if ($out === false) throw new \RuntimeException('No se pudo abrir la salida de la exportación.');
        try {
            fwrite($out, self::BOM . self::csvline(array_column($columns, 'label')));
            $n = 0;
            foreach ($rows as $row) {
                fwrite($out, self::csvline(self::rowvalues($columns, $row)));
                if ((++$n % self::FLUSHEVERY) === 0) flush();
            }
            return $n;
        } finally {
            fclose($out);
        }
    }

    static private function writexlsx(string $target, array $columns, iterable $rows): int
    {
        $book = new Spreadsheet();
        try {
            $sheet = $book->getActiveSheet();
            $letters = [];
            foreach ($columns as $i => $c) {
                $letter = $letters[$i] = Coordinate::stringFromColumnIndex($i + 1);
                $sheet->setCellValueExplicit($letter . '1', $c['label'], DataType::TYPE_STRING);
                $sheet->getColumnDimension($letter)->setWidth($c['width'] ?? max(10, mb_strlen($c['label'], 'UTF-8') + 4));
            }
            $sheet->getStyle('A1:' . end($letters) . '1')->getFont()->setBold(true);

            $n = 0;
            foreach ($rows as $row) {
                $n++;
                foreach (self::rowvalues($columns, $row) as $i => $v) {
                    $coord = $letters[$i] . ($n + 1);
                    if (self::isnumber($v)) {
                        $sheet->setCellValueExplicit($coord, $v, DataType::TYPE_NUMERIC);
                    } elseif (($v = self::text($v)) !== '') {
                        $sheet->setCellValueExplicit($coord, $v, DataType::TYPE_STRING);
                    }
                }
            }
            if ($n > 0) {
                foreach ($columns as $i => $c) {
                    if ($c['format'] !== null) $sheet->getStyle($letters[$i] . '2:' . $letters[$i] . ($n + 1))->getNumberFormat()->setFormatCode($c['format']);
                }
            }
            (new Xlsx($book))->save($target);
            return $n;
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
