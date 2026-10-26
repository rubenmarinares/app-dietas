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
    UserDomain::save($app,self::userfields($item));
    return true;
  }

  /**
   * Campos que el formulario de usuario puede cambiar (pura). Todo lo demás de $_POST se descarta: sin esta
   * lista, un POST con deadline2fa eximía a una cuenta del 2FA para siempre. "newpassword" solo cuenta si
   * viene rellena (la hashea el Model).
   */
  static function userfields(array $post): array
  {
    $out = ['iduser' => intval($post['iduser'] ?? 0)];
    foreach (['name', 'surname', 'email'] as $k) if (array_key_exists($k, $post)) $out[$k] = trim((string) $post[$k]);
    foreach (['typeuserid', 'status'] as $k) if (array_key_exists($k, $post)) $out[$k] = intval($post[$k]);
    $new = (string) ($post['newpassword'] ?? '');
    if ($new !== '') $out['password'] = $new;
    return $out;
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
    // Un usuario sin tipo se queda sin acceso (PermissionDomain::permissionsforuser): antes hay que reasignarlos.
    $n = (int) User::count($app->db, ['typeuserid' => (int) $id]);
    if ($n > 0) throw new \InvalidArgumentException("No se puede eliminar: hay $n usuarios con este tipo. Asígnales otro tipo antes.");
    $item = TypeUser::get($app->db,$id);
    if($item) TypeUser::delete($app->db,$item);
    return true;
  }
}
