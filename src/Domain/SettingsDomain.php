<?php
namespace App\Domain;
use App\Domain\Models\{TypeUser,User};

/**
 * Pantalla de Configuración: usuarios y tipos de usuario (permisos).
 * Añade aquí los catálogos ("types*") que necesite el CMS.
 */
class SettingsDomain  {

  static public function index($app,$params=[]){
    return [
      "typesuser" => TypeUser::search($app->db, []),
      "users" => User::search($app->db, []),
    ];
  }

  static public function user($app,$id){
    $item = User::get($app->db,$id);
    if(is_array($item)) unset($item["password"]);
    return $item;
  }

  static function user_save($app,$item){
    // El formulario manda "newpassword": solo se cambia si viene rellena.
    unset($item["password"]);
    if(strlen($item["newpassword"]??'')>0) $item["password"]=$item["newpassword"];
    UserDomain::save($app,$item);
    return true;
  }
  static function usercombos($app,$params=[]){
    return User::getcombos($app->db,$params);
  }

  static function user_delete($app,$id){
    // Antes usaba el get() de otro Model (copia-pega): ver docs/aprendizajes.md.
    $item = User::get($app->db,$id);
    if($item) User::delete($app->db,$item);
    return true;
  }

  static public function typeuser($app,$id){
    return TypeUser::get($app->db,$id);
  }

  static function typeuser_save($app,$item){
    // filterfields json_encodea arrays para columnas json: hay que pasar el array, no el string del textarea.
    $permissions = PermissionDomain::parsepermissionsinput(''.($item["permissions"]??''));
    $item["permissions"] = $permissions;
    if(intval($item["idtypeuser"]??0)===0){
      unset($item["idtypeuser"]);
      $saved = TypeUser::insert($app->db,$item);
    }else{
      $saved = TypeUser::upsert($app->db,$item);
    }
    // filterfields ignora null (isset) — para limpiar permisos hay que ponerlo a NULL explícitamente.
    if ($permissions === null) {
      $id = intval($item["idtypeuser"]??0);
      if ($id === 0) $id = intval($saved);
      TypeUser::clearpermissions($app->db, $id);
    }
    return true;
  }

  static public function typeuser_delete($app,$id){
    $item = TypeUser::get($app->db,$id);
    if($item) TypeUser::delete($app->db,$item);
    return true;
  }
}
