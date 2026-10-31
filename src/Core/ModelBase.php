<?php
namespace App\Core;

abstract class ModelBase
{
  static public $tablename='';
  static public $defaultorder='';
  static public $combos=[];
  static public $joins=[
//    ["table1","table1id","id",["col2","col2"],"alias","nestedtable"],
  ];
  static function primaries(){
    $keys=[];
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if($col["primary"]??false) $keys[]=$key;
    return $keys;
  }

  static function cols(){
    $keys=[];
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if($col["type"]??false) $keys[]=$key;
    return $keys;
  }
  static function maincols(){
    $keys=[];
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if($col["maincol"]??false) $keys[]=$key;
    return $keys;
  }
  static function ordercols(){
    $hashorder=false;
    $keys=[];
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if($col["ordercol"]??false) $hashorder=true;
    if(!$hashorder){
      foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if(isset($col["ordercol"]) && $col["ordercol"]==1) $keys[]=$key;
      foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if(isset($col["ordercol"]) && $col["ordercol"]==2) $keys[]=$key;
      foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$col) if(isset($col["ordercol"]) && $col["ordercol"]==3) $keys[]=$key;
    }
    return $keys;
  }

  static public $onviolation = null;

  static function violation($message) {
    $handler = static::$onviolation;
    if(is_callable($handler)){ $handler(static::$tablename, $message); return; }
    error_log('[VALIDATE] '.static::$tablename.': '.$message);
  }

  static function hasschemacolumn($tablename,$column){
    return isset($GLOBALS["dbschema"][$tablename]) && is_array($GLOBALS["dbschema"][$tablename]) && array_key_exists($column,$GLOBALS["dbschema"][$tablename]);
  }

  static function existcol($column){
    return is_string($column) && static::hasschemacolumn(static::$tablename,$column);
  }

  static function defaultorder(){
    if(strlen(''.static::$defaultorder)>0) return ' ORDER BY '.static::$defaultorder;
    $ordelcols=static::ordercols();
    $orderby='';
    if(count($ordelcols)==0) foreach(static::maincols() as $key=>$col) $orderby.=($key>0?',':'').$col.' ASC';
    else foreach($ordelcols as $key=>$col) $orderby.=($key>0?',':'').$col.' ASC';
    if(strlen($orderby)==0) 
      foreach(static::primaries() as $key=>$col) $orderby.=($key>0?',':'').$col.' DESC';
    return (strlen($orderby)>0?' ORDER BY '.$orderby:'');
  }

  static function generateUUID()
  {
      return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      // 32 bits for "time_low"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      // 16 bits for "time_mid"
      mt_rand(0, 0xffff),
      // 16 bits for "time_hi_and_version",
      // four most significant bits holds version number 4
      mt_rand(0, 0x0fff) | 0x4000,
      // 16 bits, 8 bits for "clk_seq_hi_res",
      // 8 bits for "clk_seq_low",
      // two most significant bits holds zero and one for variant DCE1.1
      mt_rand(0, 0x3fff) | 0x8000,
      // 48 bits for "node"
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
  }

  static function getcombos($db,$id=0) {
    return static::$combos;
  }

  static function getascombo($db,$filters=[]) {
    $orderby='';
    $combofiels="";
    $params=[];
    // Un combo necesita un unico `id` escalar. En tablas con primaria compuesta
    // se usa solo la primera columna: alias duplicados hacen que PDO::FETCH_ASSOC
    // se quede con el ultimo y todas las opciones acaben con el mismo value.
    $primaries=static::primaries();
    if($primaries) {
      $combofiels.=" ".$primaries[0]." as id";
      $orderby=$primaries[0].' ASC';
    }
    $maincol=0;
    foreach(static::maincols() as $key=>$col)  $combofiels.=(strlen($combofiels)>0?',':'').$col." as label".($key>0?intval($key+1):'');
    if(static::$defaultorder) $orderby=' ORDER BY '.static::$defaultorder;
    elseif(strlen($orderby??'')>0) $orderby=' ORDER BY '.$orderby;  
    
    
    $sql = "SELECT ".$combofiels." FROM ".static::$tablename." WHERE 1=1 ";
      if($filters)
      foreach($filters as $key=>$value) {
        $filter=static::_filtersql($key,$value);
        $sql.=$filter["sql"];
        $params=array_merge($params,$filter["params"]);
      }

    return $db->run($sql.$orderby, $params??[]);
  }

  
  static function tranlatecombosvalue($row){
    return static::enrichvalues($row);
  }

  static function enrichvalues($row){
    // runone() devuelve [] sin fila: sin este corte, las columnas json se añadían a null y get() de un id
    // inexistente devolvía un array no vacío (truthy).
    if (!is_array($row) || $row === []) return $row;
    if(static::$combos)
      foreach(static::$combos as $key=>$combo)
        if( array_key_exists($key,$row))
          foreach($combo as $item)
            if(isset($item["id"]) && $item["id"]==$row[$key]) $row[$key.'_label']=$item["label"];

    if (isset($GLOBALS["dbschema"][static::$tablename])) {
      foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value)
        if($value["type"]=='json') $row[$key]=json_decode($row[$key]??'',true);
    }
    return $row;
  }

  static function wherekeys(){
    $wherequery='';
    foreach(static::primaries() as $key) $wherequery.=(strlen($wherequery)>0?' AND ':'')." ".static::$tablename.".".$key."=?";
    return $wherequery;
  }
  static function lastinsert($db){
    $max=0;
    $sql = "SELECT  * FROM ".static::$tablename." WHERE 1=1 ORDER BY ";
    foreach(static::primaries() as $key =>$col) $sql.=($key>0?', ':'').$col." DESC ";
    return $db->runone($sql);
  }

  static function lastid($db){ 
    if(count(static::primaries())>1) return static::lastinsert($db);
    else return static::lastinsert($db)[static::primaries()[0]];
  }

  static function _sqlselect(){
    $cols='';
    foreach(static::cols() as $col) $cols.=(strlen($cols)>0?',':'').static::$tablename.".".$col." ";
    $sql = "SELECT ".$cols." ";

    if(static::$joins)
      foreach(static::$joins as $join) 
        if(!empty($join[3])) 
          foreach($join[3] as $key=>$value){
            $sql.=" , ".(strlen(''.($join[4]??''))>0?$join[4]:$join[0]).".".$value." ";
        } 
    return $sql;
  }

  static function _sqljoins(){
    $sql = " FROM ".static::$tablename." ";
    if(static::$joins)
      foreach(static::$joins as $join){
        // alias (4), tabla origen (5) y condición extra (6) son opcionales.
        $join += [4=>'', 5=>'', 6=>''];
        $sql.=" LEFT JOIN ".$join[0]." ".(strlen(''.$join[4])>0?" as ".$join[4]:"")." ON (".(strlen(''.$join[5])>0?$join[5]:static::$tablename).".".$join[1]."=".(strlen(''.$join[4])>0?$join[4]:$join[0]).".".$join[2]." ".(strlen(''.$join[6])>0?" ".$join[6]:"").") ";
      }
    return $sql;
  }
  static function _sqlwhere($db,$filters=[]){
    $sql=" WHERE 1=1 ";
    $params=[];
    if($filters){
      $customfilter=static::customfilter($db,$filters);
      if(strlen($customfilter["sql"])>0){
        $sql.=$customfilter["sql"];
        $params=array_merge($params,$customfilter["params"]);
      }
    }
    if($filters)
      foreach($filters as $key=>$value) {
        $filter=static::_filtersql($key,$value);
        $sql.=$filter["sql"];
        $params=array_merge($params,$filter["params"]);
      }
    return ["sql"=>$sql, "params"=>$params];
  }


  static function count($db,$filters=[]){
    $sqlselect=" SELECT COUNT(*) AS total ";
    $sqlfrom=static::_sqljoins();
    $sqlwhere=static::_sqlwhere($db,$filters);
    $sql = $sqlselect . $sqlfrom . $sqlwhere["sql"];
    $params = $sqlwhere["params"];
    return $db->runone($sql, $params)["total"];
  }
  static function customfilter($db,&$filters){
    return [
      "sql"=>"",
      "params"=>[]
    ];
  }

  /** `$order` se valida con safeorder(); vacío o no válido, defaultorder(). */
  static function search($db,$filters=[],$order=''){
    $params=[];
    $sqlselect=static::_sqlselect();
    $sqlfrom=static::_sqljoins();
    $sqlwhere=static::_sqlwhere($db,$filters);
    $sql = $sqlselect . $sqlfrom . $sqlwhere["sql"];
    $params = $sqlwhere["params"];

    $orderby=is_string($order)?static::safeorder($order):null;
    $sql.= $orderby!==null?' ORDER BY '.$orderby:static::defaultorder();
//    echo $sql; var_export($params);die;
    return array_map([static::class,'enrichvalues'],$db->run($sql, $params) ?: []);
  }

  /**
   * search() paginado: `page` 1-based acotada a [1, pages]. `$order` solo se usa si cada término
   * es `columna` o `tabla.columna` (tabla propia, de un join o su alias) existente en el esquema,
   * con ASC|DESC opcional; si no, defaultorder().
   * @return array{items:array,total:int,page:int,pages:int}
   */
  static function searchpage($db,array $filters,int $page,int $perpage,string $order=''){
    $perpage=max(1,$perpage);
    $total=(int)static::count($db,$filters);
    $pages=max(1,(int)ceil($total/$perpage));
    $page=min($pages,max(1,$page));
    $sqlwhere=static::_sqlwhere($db,$filters);
    $orderby=static::safeorder($order);
    $sql=static::_sqlselect().static::_sqljoins().$sqlwhere["sql"]
      .($orderby!==null?' ORDER BY '.$orderby:static::defaultorder())
      .' LIMIT '.$perpage.' OFFSET '.(($page-1)*$perpage);
    $items=array_map([static::class,'enrichvalues'],$db->run($sql,$sqlwhere["params"]) ?: []);
    return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>$pages];
  }

  /** ORDER BY validado contra el esquema (sin el prefijo) o null si algún término no es válido. */
  static function safeorder(string $order){
    if(trim($order)==='') return null;
    $tables=[static::$tablename=>static::$tablename];
    foreach(static::$joins as $join){
      $tables[$join[0]]=$join[0];
      if(strlen(''.($join[4]??''))>0) $tables[$join[4]]=$join[0];
    }
    $terms=[];
    foreach(explode(',',$order) as $term){
      if(!preg_match('/^\s*(?:([a-z0-9_]+)\.)?([a-z0-9_]+)(?:\s+(asc|desc))?\s*$/i',$term,$m)) return null;
      $alias=$m[1]!==''?$m[1]:static::$tablename;
      if(!isset($tables[$alias]) || !static::hasschemacolumn($tables[$alias],$m[2])) return null;
      $terms[]=$alias.'.'.$m[2].' '.strtoupper(($m[3]??'')!==''?$m[3]:'ASC');
    }
    return implode(', ',$terms);
  }

  private const COMPARISON_OPERATORS=["EQUAL"=>"=","NOT_EQUAL"=>"<>","GT"=>">","LT"=>"<","GTE"=>">=","LTE"=>"<="];

  /**
   * Accepts `column => value`, `column => [values]` (IN) or
   * `key => ["mode"=>..., "values"=>..., "field"=>?, "table"=>?]` with mode
   * EQUAL, NOT_EQUAL, LIKE, GT, LT, GTE, LTE, IN or BETWEEN. The table and
   * column must exist in the loaded schema ($GLOBALS["dbschema"]).
   */
  static function _filtersql($key,$values){
    if(is_array($values) && isset($values["mode"])) return self::modefiltersql($key,$values);
    if(is_array($values) && static::existcol($key)) return self::inlistsql($key,static::$tablename.".".$key,$values);
    if(static::existcol($key) && is_scalar($values) && strlen(''.$values)>0) return [
      "sql"=>" AND ".static::$tablename.".".$key."=? ",
      "params"=>[$values]
    ];
    if(isset($GLOBALS["debugbar"])) $GLOBALS["debugbar"]['messages']->addMessage(static::existcol($key)?"Null Value for ".$key." ":"Filter ".$key." not found in ".static::$tablename);
    return self::nofilter();
  }

  private static function modefiltersql($key,array $filter){
    $column=self::filtercolumn($key,$filter);
    if($column===null) return self::droppedfilter($key,'unknown table or column');
    $mode=is_string($filter["mode"])?$filter["mode"]:'';
    $value=$filter["values"]??null;
    if(isset(self::COMPARISON_OPERATORS[$mode]) || $mode=="LIKE"){
      if(!self::isfiltervalue($value)) return self::droppedfilter($key,$mode.' expects a single value');
      if($mode=="LIKE") return ["sql"=>" AND ".$column." LIKE ? ","params"=>["%".$value."%"]];
      return ["sql"=>" AND ".$column.self::COMPARISON_OPERATORS[$mode]."? ","params"=>[$value]];
    }
    if($mode=="IN") return self::inlistsql($key,$column,$value);
    if($mode=="BETWEEN") return self::betweensql($key,$column,$value);
    return self::droppedfilter($key,'unknown mode');
  }

  private static function filtercolumn($key,array $filter){
    $table=$filter["table"]??'';
    if($table==='') $table=static::$tablename;
    $column=static::existcol($key)?$key:($filter["field"]??null);
    if(!is_string($table) || !is_string($column) || !self::hasschemacolumn($table,$column)) return null;
    return $table.".".$column;
  }

  private static function inlistsql($key,$column,$values){
    if(!is_array($values) || !self::arefiltervalues($values)) return self::droppedfilter($key,'IN expects a list of values');
    if(count($values)==0) return ["sql"=>" AND 1=0 ","params"=>[]];
    $placeholders=implode(',', array_fill(0, count($values), '?'));
    return ["sql"=>" AND ".$column." IN (".$placeholders.") ","params"=>array_values($values)];
  }

  private static function betweensql($key,$column,$values){
    if(!is_array($values) || count($values)!=2 || !self::arefiltervalues($values))
      return self::droppedfilter($key,'BETWEEN expects two values');
    return ["sql"=>" AND ".$column.">=? AND ".$column."<=? ","params"=>array_values($values)];
  }

  private static function isfiltervalue($value){ return $value===null || is_scalar($value); }

  private static function arefiltervalues(array $values){
    foreach($values as $value) if(!self::isfiltervalue($value)) return false;
    return true;
  }

  // Dropped and reported instead of thrown: many screens hand $_POST/$_GET straight
  // to search(), so a tampered request must not become a 500.
  private static function droppedfilter($key,$reason){
    static::violation('filter '.json_encode($key).' dropped: '.$reason);
    return self::nofilter();
  }

  private static function nofilter(){ return ["sql"=>"","params"=>[]]; }

  static function selectfieldsforjoin(){
    $fields='';
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value) 
      $fields.=($fields==''?'':', ').static::$tablename.'.'.$key;
    return $fields;
  }

  static function getnewid($db,$col){
    $sql = "SELECT MAX(".$col.") as maxid FROM ".static::$tablename." WHERE 1=1 ";
    return intval($db->runone($sql)["maxid"]+1);
  }
  static function auditdates($item,$isinsert=false){
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value){
      if($key=="created" && $isinsert && !isset($item["created"])) $item["created"]=date("Ymd");
      if($key=="updated" && !isset($item["updated"])) $item["updated"]=date("Ymd");
    } 
    return $item;
  }
  static function filterfields($item) {
    $result=[];
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value) {
      // array_key_exists (no isset): un null explícito se conserva para poder escribir NULL.
      if(array_key_exists($key,$item)) {
        if($item[$key]===null){
          $result[$key]=null;
          continue;
        }
        if($value["type"]=='json') $item[$key]=json_encode($item[$key]);
        elseif($value['type']=='varchar' && isset($value['length'])) $item[$key]=mb_substr((string)$item[$key],0,(int)$value['length'],'UTF-8');
        if($value['type']=='int') $item[$key]=intval($item[$key]);
        if($value['type']=='bigint') $item[$key]=(PHP_INT_SIZE>=8?intval($item[$key]):floatval($item[$key]));
        if($value['type']=='float') $item[$key]=floatval($item[$key]);
        if($value['type']=='text') $item[$key]=''.$item[$key];
        $result[$key]=$item[$key];
      }
    }
    return $result;
  }
  static function setdefaults($item) {
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value) {
      if(!isset($item[$key]) && isset($value['default'])) $item[$key]=$value['default'];
    }
    return $item;
  }
    

  static function validate($item,$required=true) {
    foreach($GLOBALS["dbschema"][static::$tablename] as $key=>$value){
      if($required && ($value['required']??false) && !isset($item[$key])){
        if($value['autoincrement']??false) continue; // antes: return true (cortaba la validación del resto de columnas)
        echo ''.$key.' is required';
        die;
        return false;
      }
      if(intval($value['length']??'0')>0 && ( ($value['type']??'')==='varchar'
          ? mb_strlen((string)($item[$key]??''),'UTF-8')
          : strlen((string)($item[$key]??'')) ) >intval($value['length']??'0') ){ // varchar = caracteres; text/json = bytes
        echo ''.$key.' is too long';
        die;
        return false;
      } 
      if(intval($value['max']??'0')>0 && intval($item[$key]??'')>intval($value['max']??'0') ){
        echo ''.$key.' is too big';
        die;
        return false;
      } 
      if(intval($value['min']??'0')>0 && intval($item[$key]??'')<intval($value['min']??'0') ){
        echo ''.$key.' is too small';
        die;
        return false;
      } 
    }

    return true;
  }

  static function getall($db) {
    $orderby='';
    if(static::primaries()) foreach(static::primaries() as $key) $orderby=' ORDER BY '.$key.' ASC';
    $sql = "SELECT * FROM ".static::$tablename." ".$orderby;
    return array_map(function($row){return static::enrichvalues($row);},$db->run($sql));
  }

  static function get($db,$id) {
    $sqlselect=static::_sqlselect();
    $sqlfrom=static::_sqljoins();
    $sql = $sqlselect . $sqlfrom;

    if(is_array($id)){
      // Solo se filtra por las primarias realmente aportadas. Con primaria
      // compuesta, exigirlas todas generaba `col=?` con null y no casaba nunca.
      $wherekeys=[];
      $params=[];
      foreach(static::primaries() as $key) if(array_key_exists($key,$id)){
        $wherekeys[]=static::$tablename.".".$key."=?";
        $params[]=$id[$key];
      }
      if(!$wherekeys) return [];
      $sql .= ' WHERE  '.implode(' AND ',$wherekeys)." ";
      return  static::enrichvalues($db->runone($sql, $params));
    }else{
      if(count(static::primaries())>1)
        throw new \InvalidArgumentException(static::$tablename.' tiene primaria compuesta: get() requiere un array con las primarias');
      $sql .= ' WHERE  '.static::wherekeys()." ";
      return  static::enrichvalues($db->runone($sql, array($id)));
    }
  }
  
  static function upsert($db,$item) {
    if(static::exists($db,$item))
      return static::update($db,$item);
    return static::insert($db,$item);
  }

  static function insert($db,$item) {
    $uuid=false;
    $item=static::auditdates($item,true);
    $item=static::filterfields($item);
    $item=static::setdefaults($item);    
    if(!static::validate($item)) return false;

    foreach($GLOBALS["dbschema"][static::$tablename] as $key => $col){
      if($GLOBALS["dbschema"][static::$tablename][$key]['autoincrement']??false) unset($item[$key]); 
      if($GLOBALS["dbschema"][static::$tablename][$key]['UUID']??false) {
        unset($item[$key]);
        $uuid=static::generateUUID();
        $item[$key]=$uuid;
      }
    }
    $result=$db->insert(static::$tablename, $item);
    if($result){
      if($uuid) return $uuid;
      $primaries=static::primaries();
      if(count($primaries)==1){
        // Autoincremental: el id lo asigna la BBDD. Si no, es el valor aportado.
        if($GLOBALS["dbschema"][static::$tablename][$primaries[0]]['autoincrement']??false) return (int)$db->lastInsertId();
        return $item[$primaries[0]]??null;
      }
      // Primaria compuesta: los valores de las primarias del propio $item.
      $pk=[];
      foreach($primaries as $key) $pk[$key]=$item[$key]??null;
      return $pk;
    }else{
      // db::$error es privada: asignarla desde fuera lanzaba Error en vez de devolver false.
      error_log("[ModelBase] Error inserting ".static::$tablename);
      return false;
    }

  }

  static function update($db,$item) {
    $item=static::auditdates($item,false);
    $item=static::filterfields($item);
    $ids=[];
    foreach(static::primaries() as $key){
      $ids[]=$item[$key];
      unset($item[$key]);
    } 
    return $db->update(static::$tablename, $item, static::wherekeys(), $ids);
  }

  static function delete($db,$item) {
    $ids=[];
    foreach(static::primaries() as $key) $ids[]=$item[$key];
    return $db->delete(static::$tablename, static::wherekeys(), $ids);
  }
  static function exists($db,$item) {
     $ids=[];
    foreach(static::primaries() as $key) $ids[]=$item[$key];
    if($db->run("SELECT * FROM ".static::$tablename." WHERE ".static::wherekeys(), $ids)){
      return true;
    }
    return false;
  }



}
