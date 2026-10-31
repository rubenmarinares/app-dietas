<?php
namespace App\Domain\Models;
use App\Core;

class User extends Core\ModelBase {
  static public $tablename='users';

  static public $defaultorder=" users.name ASC  ";
  static public $joins=[
    ["typesuser","typeuserid","idtypeuser",["typeuser"]],
  ];
  static public $combos=[
    "status"=>[["id"=>1,"label"=>"Activo"],["id"=>0,"label"=>"Inactivo"],],
  ];

  static function getcombos($db,$filters=[]){
    $combos=static::$combos;
    $combos["typeuserid"]=TypeUser::getascombo($db);
    return $combos;
  }

  /**
   * Hash de contraseña. password_hash (bcrypt/argon según PHP) en lugar del md5
   * heredado: md5 sin sal se revierte con tablas precalculadas.
   */
  static function encrypt($pass){
    return password_hash((string)$pass, PASSWORD_DEFAULT);
  }

  /** ¿Es un hash md5 heredado (32 hex)? */
  static function islegacyhash($hash): bool
  {
    return is_string($hash) && preg_match('/^[a-f0-9]{32}$/i', $hash) === 1;
  }

  static public function search($db, $filters = [], $order = '')
  {
    $items= parent::search($db, $filters, $order);

    $sqlwhere=static::_sqlwhere($db,$filters);
    $sql = "SELECT users.iduser,MAX(users_logs.stamp) as lastaccess "
          . static::_sqljoins()
          . " LEFT JOIN users_logs ON (users.iduser = users_logs.userid) "
          . $sqlwhere["sql"]
          ." GROUP BY users.iduser " ;
    $lastaccess = $db->run($sql, $sqlwhere["params"]) ?: [];
    foreach($items as $key => $item) {
      unset($items[$key]["password"]);
      $items[$key]["lastaccess"] = 0;
      foreach($lastaccess as $la) {
        if($la["iduser"] == $item["iduser"]) {
          $items[$key]["lastaccess"] = (int) $la["lastaccess"];
          break;
        }
      }
    }

    return $items;
  }

  /** Una contraseña vacía nunca se guarda (antes se guardaba md5('')). */
  static private function preparepassword($item){
    if(array_key_exists("password",$item)){
      if((string)$item["password"]==='') unset($item["password"]);
      else $item["password"]=self::encrypt($item["password"]);
    }
    return $item;
  }

  static public function insert($db,$item){
    return parent::insert($db,self::preparepassword($item));
  }

  static public function update($db, $item)
  {
    return parent::update($db, self::preparepassword($item));
  }

  /**
   * Login por email+contraseña de un usuario activo. Devuelve la fila (sin
   * password) o [] si no casa.
   *
   * Migración transparente: si el hash guardado es md5 heredado y coincide, se
   * reescribe con password_hash en ese mismo login. Así no hay que forzar un
   * reseteo masivo de contraseñas al migrar usuarios de admin.redgdps.
   */
  static public function login($db,$email,$pass){
    $row = $db->runone("SELECT users.* FROM users WHERE users.email = ? AND users.status=1 ", [$email]);
    if(!$row || !isset($row["password"]) || (string)$row["password"]==='') return [];
    $stored = (string)$row["password"];
    $pass = (string)$pass;

    if(self::islegacyhash($stored)){
      if(!hash_equals(strtolower($stored), md5($pass))) return [];
      self::setpasswordhash($db,(int)$row["iduser"],self::encrypt($pass));
    }elseif(password_verify($pass,$stored)){
      if(password_needs_rehash($stored, PASSWORD_DEFAULT))
        self::setpasswordhash($db,(int)$row["iduser"],self::encrypt($pass));
    }else{
      return [];
    }
    unset($row["password"]);
    return $row;
  }

  /** Escribe un hash YA calculado (no vuelve a hashear). */
  static public function setpasswordhash($db,int $iduser,string $hash){
    return $db->run("UPDATE users SET password = ? WHERE iduser = ?", [$hash,$iduser]);
  }

  /** users.deadline2fa (yyyymmdd) o NULL = sin plazo de gracia. */
  static public function setdeadline2fa($db,int $iduser,?int $deadline){
    return $db->run("UPDATE users SET deadline2fa = ? WHERE iduser = ?", [$deadline,$iduser]);
  }
}
