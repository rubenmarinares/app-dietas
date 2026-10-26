<?php 
namespace App\Domain;
use App\Domain\Models\Setting;
class SettingDomain  {

  static public function list($app,$params=[]){
    return Setting::search($app->db,$params);
  }

  static public function get($app,$id){
    return Setting::get($app->db,$id);
  }

  static public function save($app,$item){
    return Setting::upsert($app->db,$item);
  }

  static public function delete($app,$id){
    return Setting::delete($app->db,Setting::get($app->db,$id));
  }


}