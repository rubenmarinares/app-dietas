<?php
/**
 * Cargador de variables de entorno desde el fichero .env de la raíz del proyecto.
 *
 * El .env no se versiona (ver .gitignore). Usa .env.example como plantilla.
 * Las variables reales del sistema (getenv) tienen prioridad sobre el fichero,
 * de modo que en producción se pueden inyectar por el servidor web o systemd.
 */

if (!function_exists('loadenv')) {
    /**
     * Parsea un fichero .env y publica sus claves en getenv()/$_ENV.
     * No sobrescribe variables ya presentes en el entorno.
     */
    function loadenv(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $total = count($lines);

        for ($i = 0; $i < $total; $i++) {
            $line = trim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $quote = ($value !== '' && ($value[0] === '"' || $value[0] === "'")) ? $value[0] : null;

            if ($quote !== null) {
                $cerradaAqui = strlen($value) >= 2 && $value[-1] === $quote;

                if ($cerradaAqui) {
                    // Valor de una sola linea entre comillas.
                    $value = substr($value, 1, -1);
                } else {
                    // Valor multilinea (p. ej. una clave PEM): acumular hasta la
                    // comilla de cierre. Conserva los saltos de linea internos.
                    $buffer = substr($value, 1);
                    while (++$i < $total) {
                        $actual = rtrim($lines[$i], "");
                        if (str_ends_with(rtrim($actual), $quote)) {
                            $actual = rtrim($actual);
                            $buffer .= "
" . substr($actual, 0, -1);
                            break;
                        }
                        $buffer .= "
" . $actual;
                    }
                    $value = $buffer;
                }
            }

            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    /** Valor de entorno como string, o $default si no está definido. */
    function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}

if (!function_exists('envint')) {
    /** Valor de entorno como int. */
    function envint(string $key, int $default = 0): int
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : (int) $value;
    }
}

if (!function_exists('envbool')) {
    /** Valor de entorno como bool. Acepta 1/true/yes/on. */
    function envbool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
