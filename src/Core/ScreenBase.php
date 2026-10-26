<?php
namespace App\Core;
use Twig\TwigFilter;
use Twig\TwigTest;

/**
 * Base de todos los controladores HTML (Screens). Recibe el App por referencia
 * y renderiza plantillas Twig de src/Screens/Views/.
 *
 * OJO: el entorno Twig se construye en CADA rendertemplate() (loader, extensiones,
 * funciones). Es simple pero no barato; si el CMS público necesita rendimiento,
 * cachear el Environment en una propiedad estática.
 */
abstract class ScreenBase
{
    protected $app;
    protected $twig;
    public function __construct(&$app, $folder = 'views')
    {
        $this->app = $app;
    }
    function render_json($data,$code=200)
    {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code($code);
      echo json_encode($data);
    }

    /**
     * Renderiza $template con $data + variables globales.
     * $echo=true imprime y consume los mensajes flash; $echo=false devuelve el HTML
     * (útil para PDFs o fragmentos) y NO consume los mensajes.
     */
    function rendertemplate($template,$data=[],$echo=true){
      $data=array_merge($data,[
          "APP_NAME"=>APP_NAME,
          "PUBLIC_PATH"=>PUBLIC_PATH,
          "APP_PATH"=>APP_PATH,
          "PUBLIC_UPLOAD_PATH"=>PUBLIC_UPLOAD_PATH,
          "messages"=> $this->app->messages,
          "logado"=>$_SESSION["logado"]??false,
          "menu"=>($GLOBALS["menu"]??[]),
          "post"=>$_POST,
          "get"=>$_GET,
          "today"=>date("Ymd"),
          "thisyear"=>date("Y"),
          "currentuser"=>$this->app->user,
          "csrf_token"=>Csrf::token(),
      ]);
      if($echo && $this->app->messages) $this->app->cleanmessages();

      $loader = new \Twig\Loader\FilesystemLoader(LOCAL_PATH.'Screens/Views/' );
      $twig = new \Twig\Environment($loader, [
          // src/cache/views/ debe existir y ser escribible (está en .gitignore salvo .gitkeep).
          'cache' => LOCAL_PATH.'cache/views/',
          'debug' => APP_DEBUG,
          'auto_reload' => true,
          'strict_variables' => false,
      ]);

      $twig->addExtension(new \Twig\Extension\DebugExtension());
      $twig->addExtension(new TwigMyExtensions());
      $app = $this->app;
      // can(modulo=actual, nivel='write'): mismo cálculo que el gate de App.
      $function = new \Twig\TwigFunction('can', function ($module = null, $level = 'write') use ($app) {
        return \App\Domain\PermissionDomain::can($app->userpermissions, $module ?? $app->currentmodule, $level);
      });
      $twig->addFunction($function);
      // debugbar: solo pinta algo con APP_DEBUG=1 (ver Bootstrap).
      $function = new \Twig\TwigFunction('debugbar_header', function () {
        return isset($GLOBALS["debugbarRenderer"]) ? $GLOBALS["debugbarRenderer"]->renderHead() : '';
      });
      $twig->addFunction($function);
      $function = new \Twig\TwigFunction('debugbar', function () {
        return isset($GLOBALS["debugbarRenderer"]) ? $GLOBALS["debugbarRenderer"]->render() : '';
      });
      $twig->addFunction($function);

      $function = new \Twig\TwigFunction('print_checkeliminar', function () use ($app) {
        if (!\App\Domain\PermissionDomain::can($app->userpermissions, $app->currentmodule, 'write')) return '';
        return '<div class="input-group float-end w-auto checkeliminar">
          <div class="input-group-text">
            <input type="checkbox"  tabindex="-1" class="checkeliminar_check" aria-label="check para activar eliminar">
          </div>
        <button type="submit" tabindex="-1" name="noeliminar" class="checkeliminar_submit btn btn-outline-danger" disabled="disabled" >Eliminar</button>
      </div>';
      });
      $twig->addFunction($function);

      $isArray= new TwigTest('array', function ($value) {
        return is_array($value);
        });
      $twig->addTest($isArray);

      if($echo)
        echo $twig->render($template, $data);
      else
        return $twig->render($template, $data);
    }

}

class TwigMyExtensions extends \Twig\Extension\AbstractExtension {

  public function getFilters() {
  return array(
    new \Twig\TwigFilter('urlencodepreserveslash', array($this, 'urlencodepreserveslash')),
    new \Twig\TwigFilter('json_decode', array($this, 'jsondecode')),
    new \Twig\TwigFilter('datetoint', array($this, 'dateToInt')),
    new \Twig\TwigFilter('datesqltofrontend', array($this, 'dateSqltoFrontend')),
    new \Twig\TwigFilter('dateinttofrontend', array($this, 'dateIntToFrontend')),
    new \Twig\TwigFilter('datesqltoint', array($this, 'dateSqltoInt')),
    new \Twig\TwigFilter('monedaaentero', array($this, 'monedaaEntero')),
    new \Twig\TwigFilter('monedaatexto', array($this, 'monedaaTexto')),
    new \Twig\TwigFilter('monedaatextous', array($this, 'monedaaTextous')),
    new \Twig\TwigFilter('truncate', array($this, 'truncate')),
    new \Twig\TwigFilter('filesizetotext', array($this, 'filesizetotext')),
  );
  }

  function urlencodepreserveslash($url) {
      return implode('/', array_map('urlencode', explode('/', $url)));
  }

  function jsondecode($string) {
      return json_decode($string ?? '',true);
  }

  function truncate($valor,$longitud=50)
  {
    if(strlen($valor??'')>$longitud)
      return substr($valor,0,$longitud)."...";
    else
      return $valor;
  }

  /** "1.234,5" → 12345000 (dinero como entero ×10000, 4 decimales fijos). */
  function monedaaEntero($valor){
    if(strlen("".$valor)==0) return '';
    $valor=str_replace(".", "","".$valor);
    $valor=str_replace(",", ".","".$valor);
    if(strpos($valor,".")=== false){
        $parteentera=intval($valor);
        $partedecimal=0;
    }else{
        $partes=explode(".", $valor);
        $parteentera=intval($partes[0]);
        $partedecimal=intval(substr(str_pad(trim($partes[1]), 4, "0"), 0, 4));
    }
    if($parteentera<0 || str_starts_with(trim("".$valor), '-')){
        return -(abs($parteentera)*10000+$partedecimal);
    }else{
        return $parteentera*10000+$partedecimal;
    }
  }

  function filesizetotext($size){
    if($size<1024)
      return $size." bytes";
    else if($size<1024*1024)
      return round($size/1024,2)." KB";
    else if($size<1024*1024*1024)
      return round($size/1024/1024,2)." MB";
    else
      return round($size/1024/1024/1024,2)." GB";
  }

  /** 12345000 → "1.234,50" */
  function monedaaTexto($valor)
  {
    $decimales=2;
    if(is_numeric($valor))
      return number_format(round($valor/pow(10,4),$decimales), 2, ',', '.');
    else
      return "0";
  }
  /** 12345000 → "1,234.50" */
  function monedaaTextous($valor)
  {
    $decimales=2;
    if(is_numeric($valor))
      return number_format(round($valor/pow(10,4),$decimales),$decimales ,'.',',' );
    else
      return "0";
  }

  /** "d/m/Y" → yyyymmdd (int) */
  function dateToInt($date) {
    $date = explode('/', ''.$date);
    if(count($date)!==3) return 0;
    return intval($date[2]*10000 +  $date[1] *100 + $date[0]);
  }

  /** "Y-m-d[ H:i:s]" → "d/m/Y" */
  function dateSqltoFrontend($date) {
    if(strlen(''.$date)==0) return '';
    if(intval(''.$date)==0) return '';
    $date = explode('-', substr(''.$date, 0, 10));
    return ($date[2]??'') . '/' . ($date[1]??'') . '/' . ($date[0]??'');
  }

  /** "Y-m-d" → "Ymd" */
  function dateSqltoInt($date) {
    if(strlen(''.$date)==0) return '';
    $date = explode('-', substr(''.$date, 0, 10));
    return ($date[0]??'') . ($date[1]??'') . ($date[2]??'');
  }

  /** yyyymmdd (int) → "d/m/Y" */
  function dateIntToFrontend($date) {
    if($date==0) return '';
    if(strlen(''.$date)==0) return '';
    $date = ''.$date;
    return substr($date,6,2) . '/' . substr($date,4,2) . '/' . substr($date,0,4);
  }

}
