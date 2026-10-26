<?php 
namespace App\Domain\Models;
use App\Core;

class TypeUser extends Core\ModelBase {
  static public $tablename='typesuser';

  static function clearpermissions($db, $idtypeuser){
    return $db->run("UPDATE typesuser SET permissions = NULL WHERE idtypeuser = ?", [intval($idtypeuser)]);
  }
}
