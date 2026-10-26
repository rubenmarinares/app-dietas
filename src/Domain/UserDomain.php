<?php
namespace App\Domain;
use App\Domain\Models\{User};

class UserDomain  {
  static public function getcombos($app,$params=[]){
    return User::getcombos($app->db,$params);
  }
  static public function getascombo($app,$params=[]){
    return User::getascombo($app->db,$params);
  }
  static public function list($app,$params){
    return User::search($app->db,$params);
  }

  static public function get($app,$id){
    return User::get($app->db,$id);
  }

  /**
   * Alta o modificación. En un alta (sin iduser o iduser=0) se concede plazo de
   * gracia para configurar 2FA: sin él, el usuario nuevo no podría entrar.
   * La contraseña llega en claro y la hashea el Model; vacía = no se toca.
   */
  static public function save($app,$item){
    if(intval($item["iduser"]??0)===0){
      unset($item["iduser"]);
      if(!array_key_exists("deadline2fa",$item)) $item["deadline2fa"]=AuthDomain::gracedeadline();
      return User::insert($app->db,$item);
    }
    return User::upsert($app->db,$item);
  }

  /** Cambio de contraseña propio. Devuelve false si está vacía o no coincide la repetición. */
  static public function changepassword($app,int $iduser,string $newpassword,string $repeat){
    if($newpassword==='' || $newpassword!==$repeat) return false;
    User::update($app->db,["iduser"=>$iduser,"password"=>$newpassword]);
    return true;
  }

  static public function delete($app,$id){
    return User::delete($app->db,User::get($app->db,$id));
  }
}
