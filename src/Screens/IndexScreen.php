<?php
namespace App\Screens;
use App\Core;
use App\Core\Csrf;
use App\Domain\UserDomain;

/**
 * Portada del panel y perfil propio. El módulo "index" está en
 * PermissionDomain::ALWAYSALLOWED: cualquier usuario autenticado entra.
 * App ya garantiza sesión antes de llegar aquí (gate de autenticación).
 */
Class IndexScreen extends Core\ScreenBase
{
  /** Ruta '' → portada del panel (o login si no hay sesión). */
  public function noroute(){
    header("Location: ".APP_PATH.(empty($_SESSION["logado"]) ? "login" : "index"));
    die;
  }

  /** Dashboard de ejemplo: sustituir por el resumen del CMS. */
  public function index()
  {
    $this->rendertemplate('index.html',[]);
  }

  public function myprofile(){
    $data = [];
    if($_POST){
      if(!Csrf::validate($_POST["csrf_token"] ?? '')){
        $data["errorcsrf"] = true;
      }else{
        $ok = UserDomain::changepassword(
          $this->app,
          (int)$this->app->user["iduser"],
          (string)($_POST["newpassword"] ?? ''),
          (string)($_POST["repeatnewpassword"] ?? '')
        );
        if($ok) $data["passwordchanged"] = true;
        else $data["errorpass"] = true;
      }
    }

    $this->rendertemplate('myprofile.html',$data);
  }
}
