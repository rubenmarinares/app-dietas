<?php
namespace App\Screens;
use App\Core;
use App\Core\Csrf;
use App\Domain\{SettingsDomain};

/**
 * Configuración: usuarios y tipos de usuario. Módulo de permisos "settings".
 * Los formularios son sidepanels (offcanvas) cargados por AJAX: GET pinta el
 * formulario, POST guarda (o elimina si llega "eliminar") y redirige a settings.
 */
Class SettingsScreen extends Core\ScreenBase
{
  public function index(){
    $items=SettingsDomain::index( $this->app,[]);
    $this->rendertemplate('settings.html', [
      "typesuser"=>$items["typesuser"],
      "users"=>$items["users"],
    ]);
  }

  /** POST con token CSRF válido; si no, mensaje y vuelta a settings. */
  private function validpost(): bool
  {
    if(Csrf::validate($_POST["csrf_token"] ?? '')) return true;
    $this->app->addmessage(['type'=>'danger','text'=>'El formulario ha caducado. No se ha guardado nada.']);
    return false;
  }

  public function user($id){
    if($_POST){
      if($this->validpost()){
        $item=$_POST;
        $item["iduser"]=intval($id);
        if(isset($_POST["eliminar"])){
          if(intval($id) === intval($this->app->user["iduser"] ?? 0))
            $this->app->addmessage(['type'=>'danger','text'=>'No puedes eliminar tu propio usuario.']);
          else SettingsDomain::user_delete( $this->app,$id);
        }
        else SettingsDomain::user_save( $this->app,$item);
      }
      $this->app->redirect("settings");
    }
    if($id==0) $item=["iduser"=>0];
    else $item=SettingsDomain::user( $this->app,$id);
    $this->rendertemplate('sidepanels/user_edit.html', [
      "item"=>$item,
      "combos"=>SettingsDomain::usercombos( $this->app,[]),
    ]);
  }

  public function typeuser($id){
    if($_POST){
      if($this->validpost()){
        $item=$_POST;
        $item["idtypeuser"]=intval($id);
        if(isset($_POST["eliminar"])){
          try{
            SettingsDomain::typeuser_delete( $this->app,$id);
          }catch(\InvalidArgumentException $e){
            $this->app->addmessage(['type'=>'danger','text'=>$e->getMessage()]);
          }
        }
        else{
          try{
            SettingsDomain::typeuser_save( $this->app,$item);
          }catch(\InvalidArgumentException $e){
            $this->app->addmessage(['type'=>'danger','text'=>"Permisos no guardados: ".$e->getMessage()]);
          }
        }
      }
      $this->app->redirect("settings");
    }
    if($id==0) $item=["idtypeuser"=>0];
    else $item=SettingsDomain::typeuser( $this->app,$id);
    $this->rendertemplate('sidepanels/typeuser.html', [
      "item"=>$item,
    ]);
  }

}
