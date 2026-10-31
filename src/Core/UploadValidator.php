<?php declare(strict_types=1);

namespace App\Core;

/** Validación de ficheros subidos: extensión en lista blanca, MIME real (finfo) coherente y tamaño. */
final class UploadValidator
{
    private const OOXML = 'application/vnd.openxmlformats-officedocument.';

    /** Bytes de control que no aparecen en texto (se admiten tabulador y saltos): NUL, UTF-16 o binario. */
    private const CSVBINARY = '~[\x00-\x08\x0E-\x1F\x7F]~';

    /**
     * Código PHP o HTML activo en cualquier punto del CSV (finfo solo mira el principio del fichero).
     * Solo etiquetas concretas: un `<` suelto es un dato normal («<7%»).
     */
    private const CSVACTIVE = '~<(?:\?(?:php\b|=|\s)|!doctype\b|/?(?:html|head|body|script|iframe|object|embed|svg|meta)\b)~i';

    /** extensión => [tipo, MIMEs aceptados] */
    static private function map(): array
    {
        $zip = ['application/zip', 'application/x-zip-compressed'];
        $ole = ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'];
        // octet-stream solo se acepta para OOXML (finfo a veces no reconoce el contenedor).
        $ooxml = array_merge($zip, ['application/octet-stream']);
        return [
            'jpg'  => ['imagen', ['image/jpeg']],
            'jpeg' => ['imagen', ['image/jpeg']],
            'png'  => ['imagen', ['image/png']],
            'gif'  => ['imagen', ['image/gif']],
            'webp' => ['imagen', ['image/webp']],
            'pdf'  => ['documento', ['application/pdf']],
            'doc'  => ['documento', array_merge(['application/msword'], $ole)],
            'xls'  => ['documento', array_merge(['application/vnd.ms-excel'], $ole)],
            'ppt'  => ['documento', array_merge(['application/vnd.ms-powerpoint'], $ole)],
            'docx' => ['documento', array_merge([self::OOXML . 'wordprocessingml.document'], $ooxml)],
            'xlsx' => ['documento', array_merge([self::OOXML . 'spreadsheetml.sheet'], $ooxml)],
            'pptx' => ['documento', array_merge([self::OOXML . 'presentationml.presentation'], $ooxml)],
            'zip'  => ['documento', $zip],
            // finfo da text/plain a casi todo CSV: los tipos de texto solo valen aquí y csvcontent mira el contenido.
            'csv'  => ['documento', ['text/plain', 'text/csv', 'application/csv']],
        ];
    }

    private const ERRORS = [
        UPLOAD_ERR_INI_SIZE   => 'El fichero supera el tamaño máximo permitido por el servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'El fichero supera el tamaño máximo permitido.',
        UPLOAD_ERR_PARTIAL    => 'El fichero se subió de forma incompleta. Inténtalo de nuevo.',
        UPLOAD_ERR_NO_FILE    => 'No se ha seleccionado ningún fichero.',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor.',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo guardar el fichero en el servidor.',
        UPLOAD_ERR_EXTENSION  => 'Una extensión del servidor detuvo la subida.',
    ];

    /**
     * @param array{name?:string,tmp_name?:string,size?:int,error?:int} $file entrada de $_FILES
     * @param string[]|null $allowedext restringe las extensiones en esta llamada (null = toda la lista blanca);
     *                                  nunca añade una extensión que no esté en la lista blanca de la clase
     * @return array{tipo:string,mime:string,ext:string,tamano:int,ancho:?int,alto:?int}
     */
    static public function check(array $file, int $maxbytes, bool $requireuploaded = true, ?array $allowedext = null): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::ERRORS[$error] ?? 'Error desconocido al subir el fichero.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new \InvalidArgumentException('No se encontró el fichero subido.');
        }
        if ($requireuploaded && !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('El fichero no procede de una subida válida.');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $map = self::map();
        if ($ext === '' || !isset($map[$ext])) {
            throw new \InvalidArgumentException('Tipo de fichero no permitido.');
        }
        if ($allowedext !== null && !in_array($ext, array_map(static fn($e) => strtolower((string) $e), $allowedext), true)) {
            throw new \InvalidArgumentException('Tipo de fichero no permitido.');
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new \InvalidArgumentException('El fichero está vacío.');
        }
        if ($size > $maxbytes) {
            throw new \InvalidArgumentException('El fichero supera el tamaño máximo permitido (' . round($maxbytes / 1048576, 1) . ' MB).');
        }

        [$tipo, $mimes] = $map[$ext];
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, $mimes, true)) {
            throw new \InvalidArgumentException('El contenido del fichero no coincide con su extensión.');
        }

        // Contenedores ZIP (OOXML y zip): cabecera PK y, en OOXML, [Content_Types].xml.
        if (in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            if (file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04") {
                throw new \InvalidArgumentException('El contenido del fichero no coincide con su extensión.');
            }
            if (in_array($ext, ['docx', 'xlsx', 'pptx'], true) && class_exists(\ZipArchive::class)) {
                $zip = new \ZipArchive();
                $ok = $zip->open($tmp) === true && $zip->locateName('[Content_Types].xml') !== false;
                if ($ok) $zip->close();
                if (!$ok) {
                    throw new \InvalidArgumentException('El documento Office no es válido.');
                }
            }
        }

        if ($ext === 'csv') {
            self::csvcontent((string) file_get_contents($tmp));
        }

        $ancho = $alto = null;
        if ($tipo === 'imagen') {
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new \InvalidArgumentException('La imagen no es válida.');
            }
            $ancho = (int) $info[0];
            $alto = (int) $info[1];
        }

        return ['tipo' => $tipo, 'mime' => $mime, 'ext' => $ext, 'tamano' => $size, 'ancho' => $ancho, 'alto' => $alto];
    }

    /** Texto (UTF-8 o de 8 bits, como lee TabularReader) sin binario ni contenido activo y con algo más que el BOM. */
    static private function csvcontent(string $bytes): void
    {
        // Un error de PCRE (false) también rechaza.
        if (preg_match(self::CSVBINARY, $bytes) !== 0 || preg_match(self::CSVACTIVE, $bytes) !== 0) {
            throw new \InvalidArgumentException('El contenido del fichero no coincide con su extensión.');
        }
        if (trim(str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes) === '') {
            throw new \InvalidArgumentException('El fichero está vacío.');
        }
    }
}
