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

  /** Longitud mínima de una contraseña del panel (igual que tools/create_admin.php). */
  public const MINPASSWORD = 10;

  /**
   * Cambio de contraseña propio: exige la contraseña actual (con una sesión robada no se puede secuestrar la
   * cuenta). Devuelve null si se cambió o el mensaje del error. Cambiarla cierra las demás sesiones del usuario
   * (App compara la huella de la contraseña en cada petición).
   */
  static public function changepassword($app,int $iduser,string $current,string $newpassword,string $repeat): ?string{
    $row = User::get($app->db,$iduser);
    if(!self::passwordmatches($row["password"] ?? null,$current)) return 'La contraseña actual no es correcta.';
    $issue = self::passwordissue($newpassword,$repeat);
    if($issue !== null) return $issue;
    User::update($app->db,["iduser"=>$iduser,"password"=>$newpassword]);
    return null;
  }

  /** Problema de una contraseña nueva (pura) o null si vale. */
  static public function passwordissue(string $new,string $repeat): ?string{
    if($new==='') return 'La contraseña nueva no puede estar vacía.';
    if(mb_strlen($new) < self::MINPASSWORD) return 'La contraseña nueva debe tener al menos '.self::MINPASSWORD.' caracteres.';
    if(!hash_equals($new,$repeat)) return 'Las dos contraseñas nuevas no coinciden.';
    return null;
  }

  /** ¿$given es la contraseña del hash guardado? (pura; acepta el md5 heredado, como User::login). */
  static public function passwordmatches(?string $stored,string $given): bool{
    $stored = (string)$stored;
    if($stored==='' || $given==='') return false;
    if(User::islegacyhash($stored)) return hash_equals(strtolower($stored), md5($given));
    return password_verify($given,$stored);
  }

  static public function delete($app,$id){
    return User::delete($app->db,User::get($app->db,$id));
  }
}
