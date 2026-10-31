<?php declare(strict_types=1);

namespace App\Core;

/**
 * Generación de slugs URL. La transliteración no depende del locale del SO:
 * primero un mapa propio para los caracteres del español y solo después iconv como respaldo.
 */
final class Slug
{
    private const MAP = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
        'º' => 'o', 'ª' => 'a', '¿' => '', '¡' => '',
    ];

    /** Puede devolver '' (texto sin caracteres válidos): quien llama debe aportar un valor de respaldo. */
    static public function make(string $text): string
    {
        $text = strtr($text, self::MAP);
        if (preg_match('/[^\x00-\x7F]/', $text)) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
        return trim($text, '-');
    }

    /** @param callable(string):bool $exists */
    static public function unique(string $slug, callable $exists): string
    {
        if ($slug === '') {
            throw new \InvalidArgumentException('El slug base no puede estar vacío.');
        }
        $candidate = $slug;
        $n = 2;
        while ($exists($candidate)) {
            $candidate = $slug . '-' . $n++;
        }
        return $candidate;
    }
}
