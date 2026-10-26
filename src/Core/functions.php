<?php
/**
 * Helpers globales (sin namespace). Se cargan en Bootstrap antes que nada.
 *
 * Convenciones heredadas:
 *  - Fechas "numéricas": int yyyymmdd (p. ej. 20261020).
 *  - Fecha-hora numérica: int yyyymmddhhiiss.
 *  - Dinero: int con 4 decimales fijos (importe × 10000).
 *
 * Para CSRF NO hay helpers aquí: usar App\Core\Csrf (un único mecanismo).
 */

/** Tipo MIME a partir de la extensión; si no se conoce, finfo sobre el fichero. */
function mime_content_type_fronname($filename) {
  $mime_types = array(
            'txt' => 'text/plain',
            'htm' => 'text/html',
            'html' => 'text/html',
            'php' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'application/xml',
            // images
            'png' => 'image/png',
            'jpe' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'ico' => 'image/vnd.microsoft.icon',
            'tiff' => 'image/tiff',
            'tif' => 'image/tiff',
            'svg' => 'image/svg+xml',
            'svgz' => 'image/svg+xml',
            'webp' => 'image/webp',
            // archives
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            // audio/video
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'qt' => 'video/quicktime',
            'mov' => 'video/quicktime',
            // adobe
            'pdf' => 'application/pdf',
            'psd' => 'image/vnd.adobe.photoshop',
            'ai' => 'application/postscript',
            'eps' => 'application/postscript',
            'ps' => 'application/postscript',
            // ms office
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'rtf' => 'application/rtf',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            // open office
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        );

  $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
  if (array_key_exists($ext, $mime_types)) return $mime_types[$ext];
  elseif(function_exists('finfo_open') && is_file($filename)){
            $finfo = finfo_open(FILEINFO_MIME);
            $mimetype = finfo_file($finfo, $filename);
            finfo_close($finfo);
            return $mimetype;
  }else return 'application/octet-stream';
}

/** Cuenta las líneas de un CSV. */
function csv_countlines($filename='', $delimiter=',')
{
    if(!file_exists($filename) || !is_readable($filename))
        return FALSE;
    $count=0;
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {
        while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE  )
        {
          $count++;
        }
        fclose($handle);
    }
    return $count;
}

/** Devuelve las filas [$from, $to) de un CSV (sin tratar cabecera). */
function csv_part_to_array($filename='', $delimiter=',',$from=0,$to=1000)
{
    if(!file_exists($filename) || !is_readable($filename))
        return FALSE;

    $data = array();
    $count=0;
    if (($handle = fopen($filename, 'r')) !== FALSE)
    {
        while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE  && $count<$to)
        {
          if($count>=$from && $count<$to){
            $data[] = $row;
          }
          $count++;
        }
        fclose($handle);
    }
    return $data;
}

/** Nombre de fichero seguro para disco/URL. */
function filter_filename($filename, $beautify=true) {
  $filename = preg_replace(
      '~
      [<>:"/\\\|?*]|            # reservados del sistema de ficheros
      [\x00-\x1F]|             # caracteres de control
      [\x7F\xA0\xAD]|          # no imprimibles: DEL, NO-BREAK SPACE, SOFT HYPHEN
      [#\[\]@!$&\'()+,;=]|     # reservados en URI (RFC 3986)
      [{}^\~`]                 # inseguros en URL (RFC 1738)
      ~x',
      '-', $filename);
  $filename=iconv("UTF-8", "ASCII//TRANSLIT", $filename);
  // evita ".", ".." o ficheros ocultos
  $filename = ltrim($filename, '.-');
  if ($beautify) $filename = beautify_filename($filename);
  // máximo 255 bytes
  $ext = pathinfo($filename, PATHINFO_EXTENSION);
  $filename = mb_strcut(pathinfo($filename, PATHINFO_FILENAME), 0, 255 - ($ext ? strlen($ext) + 1 : 0), mb_detect_encoding($filename)) . ($ext ? '.' . $ext : '');
  return $filename;
}
function beautify_filename($filename) {
  $filename = preg_replace(array('/ +/', '/_+/', '/-+/'), '-', $filename);
  $filename = preg_replace(array('/-*\.-*/', '/\.{2,}/'), '.', $filename);
  $filename = mb_strtolower($filename, mb_detect_encoding($filename));
  $filename = trim($filename, '.-');
  return $filename;
}

/** Suma días/meses/años a una fecha yyyymmdd y devuelve yyyymmdd (string). */
function fechanumerosuma($valor,$sumadias=0,$sumameses=0,$sumaanos=0){
  $mes=($valor%10000-(($valor%10000)%100))/100;
  $dia=(int)($valor%10000)%100;
  $ano=($valor-($valor%10000))/10000;
  return date("Ymd",mktime(0,0,0,$mes+$sumameses,$dia+$sumadias,$ano+$sumaanos));
}

/** Hoy como int yyyymmdd. */
function hoy(){
  return (int) date("Ymd");
}

/** Ahora como int yyyymmddhhiiss. */
function hoyyahora(){
  return (int) date("YmdHis");
}

/** Semana ISO de una fecha yyyymmdd. */
function semana($fecha){
  $ano=substr($fecha,0,4);
  $mes=substr($fecha,4,2);
  $dia=substr($fecha,6,2);
  return date("W",mktime(1,0,0,$mes,$dia,$ano));
}
/** Fecha inicio (aprox.) de la semana N del año, yyyymmdd. */
function semanafechaini($ano,$semana){
  return date("Ymd",mktime(0,0,0,1,1,$ano)+($semana-1)*7*24*60*60);
}
/** Fecha fin (aprox.) de la semana N del año, yyyymmdd. */
function semanafechafin($ano,$semana){
  return date("Ymd",mktime(0,0,0,1,1,$ano)+($semana)*7*24*60*60-1);
}

/** yyyymmddhhiiss → "d/m/Y H:i:s" */
function hoyyahoraatexto($valor=''){
  $seg=substr("".$valor,12,2);
  $min=substr("".$valor,10,2);
  $hora=substr("".$valor,8,2);
  $dia=substr("".$valor,6,2);
  $mes=substr("".$valor,4,2);
  $ano=substr("".$valor,0,4);

  if($seg=="") $seg="00";
  if($min=="") $min="00";
  if($hora=="") $hora="00";

  return $dia."/".$mes."/".$ano." ".$hora.":".$min.":".$seg;
}

/** Entero ×10^$decimales → "1234,56" (sin separador de miles). */
function monedaenteroatexto($entero,$decimales=4){
  $parteentera=intval(intval($entero)/pow(10,$decimales));
  $resto=intval($entero)-$parteentera*pow(10,$decimales);
  if($resto<0) $resto=-1*$resto;
  $signo=($entero<0 && $parteentera==0)?'-':'';
  return $signo.$parteentera.','.str_pad(round($resto/(pow(10,$decimales-2))),2,'0',STR_PAD_LEFT);
}

/** "1.234,56" → entero ×10^$decimales. */
function monedatextoaentero($moneda,$decimales=4){
  $moneda=str_replace(".","",$moneda);
  $moneda=floatval(str_replace(",",".",$moneda));
  return round($moneda*pow(10,$decimales));
}

/** "d/m/Y" → int yyyymmdd, o false si no es una fecha válida. */
function fechatextoanumero($valor){
  $partes = explode('/', trim((string)$valor));
  if(count($partes)!==3) return false;
  [$dia,$mes,$ano] = array_map('intval', $partes);
  if(!checkdate($mes,$dia,$ano)) return false;
  return $ano*10000+$mes*100+$dia;
}

/** int yyyymmdd → "d/m/Y" ('' si vacío o 0). */
function fechanumeroatexto($valor=0){
  $valor = (string)($valor ?? '');
  if (!ctype_digit($valor) || intval($valor)<=0) return '';
  $valor = intval($valor);
  $mes=($valor%10000-(($valor%10000)%100))/100;
  $dia=(int)($valor%10000)%100;
  $ano=($valor-($valor%10000))/10000;
  return str_pad((string)$dia,2,'0',STR_PAD_LEFT)."/".str_pad((string)$mes,2,'0',STR_PAD_LEFT)."/".str_pad((string)$ano,4,'0',STR_PAD_LEFT);
}

/** Hora numérica hmm/hhmm → "h:mm"/"hh:mm". */
function horanumeroatexto($valor){
  $valor=strval($valor);
  if(strlen($valor)==3){
    return substr($valor,0,1).":".substr($valor,1,2);
  }else{
    return substr($valor,0,2).":".substr($valor,2,2);
  }
}
/** Hora numérica hmm/hhmm → minutos desde medianoche. */
function horanumeroamin($valor){
  $valor=strval($valor);
  if(strlen($valor)==3){
    return intval(substr($valor,0,1))*60+intval(substr($valor,1,2));
  }else{
    return intval(substr($valor,0,2))*60+intval(substr($valor,2,2));
  }
}

/** yyyymmddhhiiss → "d/m/Y H:i:s" */
function fechahoranumeroatexto($valor){
  $valor=strval($valor);
  return substr($valor,6,2)."/".substr($valor,4,2)."/".substr($valor,0,4)." ".substr($valor,8,2).":".substr($valor,10,2).":".substr($valor,12,2);
}
