<?php declare(strict_types=1);

namespace App\Core;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lee una hoja (.xlsx, .xls o .csv) como cabeceras + filas asociativas, para importaciones.
 * La cabecera es la primera fila con dos o más celdas (ver headerindex); las filas vacías se saltan; `fila` es el número de fila en la hoja
 * (1-based), para que los informes coincidan con lo que el usuario ve en Excel. Valores siempre como texto recortado
 * (en Excel, el valor formateado: un teléfono numérico sale como «612345678», no en notación científica).
 */
final class TabularReader
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];
    public const MAXCOLUMNS = 100;
    public const ROWMARGIN = 50;

    static public function supports(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::EXTENSIONS, true);
    }

    /**
     * @return array{headers: string[], rows: list<array{fila: int, values: array<string, string>}>, total: int}
     * Con $maxrows > 0 deja de guardar filas al llegar al tope, pero sigue contando `total`.
     * @throws \InvalidArgumentException
     */
    static public function read(string $path, string $ext, int $maxrows = 0): array
    {
        $ext = strtolower($ext);
        if (!in_array($ext, self::EXTENSIONS, true)) throw new \InvalidArgumentException('Formato no admitido (usa .xlsx, .xls o .csv).');
        if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('No se pudo leer el archivo.');
        $unread = 0;
        if ($ext === 'csv') {
            $grid = self::csvgrid((string) file_get_contents($path));
        } else {
            $sheet = self::sheetgrid($path, $maxrows);
            $grid = $sheet['grid'];
            // Filas que el filtro no llegó a cargar: cuentan para `total` (se tratan como no vacías).
            if ($sheet['lastrow'] > 0) $unread = max(0, $sheet['sheetrows'] - $sheet['lastrow']);
        }

        $grid = array_map(static fn($cells) => array_map(static fn($v) => trim(is_scalar($v) ? (string) $v : ''), $cells), $grid);
        $start = self::headerindex($grid);
        $headers = null;
        $rows = [];
        $total = 0;
        foreach ($grid as $i => $cells) {
            if ($i < $start || implode('', $cells) === '') continue;
            if ($headers === null) {
                $headers = self::headers($cells);
                continue;
            }
            $total++;
            if ($maxrows > 0 && count($rows) >= $maxrows) continue;
            $cells = array_pad(array_slice($cells, 0, count($headers)), count($headers), '');
            $rows[] = ['fila' => $i + 1, 'values' => array_combine($headers, $cells)];
        }
        if ($headers === null) throw new \InvalidArgumentException('El archivo está vacío.');
        return ['headers' => $headers, 'rows' => $rows, 'total' => $total + $unread];
    }

    /**
     * Fila de la cabecera: la primera con al menos dos celdas rellenas (salta títulos de una sola celda encima de la
     * tabla); si ninguna tiene dos (hoja de una sola columna), la primera no vacía. -1 si la hoja está vacía.
     */
    static private function headerindex(array $grid): int
    {
        $first = -1;
        foreach ($grid as $i => $cells) {
            $filled = count(array_filter($cells, static fn($v) => $v !== ''));
            if ($filled >= 2) return $i;
            if ($filled === 1 && $first < 0) $first = $i;
        }
        return $first;
    }

    /** Cabeceras únicas: vacía → «Columna N»; repetida → «X (2)». */
    static private function headers(array $cells): array
    {
        $out = [];
        $seen = [];
        foreach (array_values($cells) as $i => $h) {
            if ($h === '') $h = 'Columna ' . ($i + 1);
            $base = $h;
            $n = 1;
            while (isset($seen[$h])) $h = $base . ' (' . ++$n . ')';
            $seen[$h] = true;
            $out[] = $h;
        }
        return $out;
    }

    /**
     * Filtro de lectura: hasta MAXCOLUMNS columnas y, con $maxrows > 0, hasta $maxrows + ROWMARGIN filas (margen para
     * títulos y filas vacías sobre la tabla). Sin él, un .xlsx de 5 MB puede tener millones de celdas y agotar la memoria.
     */
    static public function readfilter(int $maxrows): IReadFilter
    {
        $lastrow = $maxrows > 0 ? $maxrows + self::ROWMARGIN : 0;
        return new class($lastrow) implements IReadFilter {
            public function __construct(private int $lastrow) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return ($this->lastrow === 0 || $row <= $this->lastrow)
                    && Coordinate::columnIndexFromString($columnAddress) <= TabularReader::MAXCOLUMNS;
            }
        };
    }

    /**
     * Primera hoja del libro, solo dentro de readfilter, con el valor formateado (no `setReadDataOnly`: hace falta el
     * formato numérico para que un teléfono salga como se ve). @return array{grid: array<int, array<int, mixed>>, sheetrows: int, lastrow: int}
     */
    static private function sheetgrid(string $path, int $maxrows): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $info = method_exists($reader, 'listWorksheetInfo') ? $reader->listWorksheetInfo($path) : [];
            if ($info) $reader->setLoadSheetsOnly([$info[0]['worksheetName']]);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(self::readfilter($maxrows));
            $sheet = $reader->load($path)->getSheet(0);
            return [
                'grid' => array_values($sheet->toArray('', true, true, false)),
                'sheetrows' => (int) ($info[0]['totalRows'] ?? 0),
                'lastrow' => $maxrows > 0 ? $maxrows + self::ROWMARGIN : 0,
            ];
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('No se pudo leer el archivo.');
        }
    }

    /** @return array<int, array<int, string>> CSV en UTF-8 (sin BOM; desde Windows-1252 si no es UTF-8 válido) */
    static private function csvgrid(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
        if (!mb_check_encoding($bytes, 'UTF-8')) $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        $delimiter = self::delimiter($bytes);
        $h = fopen('php://memory', 'r+');
        fwrite($h, $bytes);
        rewind($h);
        $grid = [];
        while (($row = fgetcsv($h, 0, $delimiter, '"', '')) !== false) $grid[] = $row === [null] ? [] : $row;
        fclose($h);
        return $grid;
    }

    /** El más frecuente de `;` `,` y tabulador en la primera línea no vacía (por defecto `;`). */
    static private function delimiter(string $bytes): string
    {
        foreach (preg_split('~\r\n|\r|\n~', $bytes) ?: [] as $line) {
            if (trim($line) === '') continue;
            $best = ';';
            $max = 0;
            foreach ([';', ',', "\t"] as $d) {
                $n = substr_count($line, $d);
                if ($n > $max) [$best, $max] = [$d, $n];
            }
            return $best;
        }
        return ';';
    }
}
