<?php  
namespace App\Core;

use DebugBar\DataCollector\PDO\TraceablePDO;
use DebugBar\DataCollector\PDO\PDOCollector;


final class Database  
{  
  private static $dns       = \DNS;  
  private static $username  = \USERNAME;  
  private static $password  = \PASSWORD;  
  private static $instance;  
      
  private function __construct() { }  
      
  /** 
   * Crea una instancia de la clase PDO 
   *  
   * @access public static 
   * @return object de la clase PDO 
   */  
  public static function getInstance()  
  {  
    if (!isset(self::$instance))  
    {  
  //    self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
     // self::$instance->setErrorCallbackFunction("echo");        
      if(isset($GLOBALS["debugbar"])){
        self::$instance = new db(self::$dns, self::$username, self::$password);  
        self::$instance = new TraceablePDO(self::$instance);
        $GLOBALS["debugbar"]->addCollector(new PDOCollector(self::$instance));
        $GLOBALS["debugbar"]['pdo']->setDurationBackground(true);   
      }else{
        self::$instance = new db(self::$dns, self::$username, self::$password);
      }

    }  
    return self::$instance;  
  }  
   public function close()  
  {  

    if (isset(self::$instance))  
    {  
      self::$instance = null;  
    }  
  } 

 
 /** 
  * Impide que la clase sea clonada 
  *  
  * @access public 
  * @return string trigger_error 
  */  
  public function __clone()  
  {  
    trigger_error('Clone is not allowed.', E_USER_ERROR);  
  }  
}  

class db extends \PDO {
  private $error;
  private $sql;
  private $bind;
  private $errorCallbackFunction;
  private $errorMsgFormat;

  public function geterror(){
    return $this->error;
  }

  public function result(){
    if(strlen(''.$this->error)>0) return false;
    return true;
  }


  public function __construct($dsn, $user="", $passwd="") {
    $options = array(

      \PDO::ATTR_CASE => \PDO::CASE_LOWER,
      \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8;SET CHARACTER SET 'UTF8';",
      \PDO::ATTR_STRINGIFY_FETCHES => true
    );
 //      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
//       \PDO::ATTR_PERSISTENT => true, 


    $this->errorCallbackFunction="print_r";


    try {
      parent::__construct($dsn, $user, $passwd, $options);
    } catch (\PDOException $e) {
      // Antes: echo + seguir con un PDO sin inicializar (fatal más adelante). Ahora falla explícito.
      error_log('[DB] conexión fallida: '.$e->getMessage());
      throw new \RuntimeException('No se pudo conectar a la base de datos'.((defined('APP_DEBUG') && APP_DEBUG) ? ': '.$e->getMessage() : ''), 0, $e);
    }
  }

  public function debug() {
    if(!empty($this->errorCallbackFunction)) {
      $error = array("Error" => $this->error);
      if(!empty($this->sql))
        $error["SQL Statement"] = $this->sql;
      if(!empty($this->bind))
        $error["Bind Parameters"] = trim(print_r($this->bind, true));

      $backtrace = debug_backtrace();
      if(!empty($backtrace)) {
        foreach($backtrace as $info) {
          if(isset($info["file"]) && $info["file"] != __FILE__)
            $error["Backtrace"] = $info["file"] . " at line " . $info["line"];  
        }   
      }

      $msg = "";
      if($this->errorMsgFormat == "html") {
        if(!empty($error["Bind Parameters"]))
          $error["Bind Parameters"] = "<pre>" . $error["Bind Parameters"] . "</pre>";
        $msg .= '<style type="text/css">.db-error{color:red;}</style>';
        $msg .= "\n" . '<div class="db-error">' . "\n\t<h3>SQL Error</h3>";
        foreach($error as $key => $val)
          $msg .= "\n\t<label>" . $key . ":</label>" . $val;
        $msg .= "\n\t</div>\n</div>";
      }
      elseif($this->errorMsgFormat == "text") {
        $msg .= "SQL Error\n" . str_repeat("-", 50);
        foreach($error as $key => $val)
          $msg .= "\n\n$key:\n$val";
      }

      $func = $this->errorCallbackFunction;
      $func($msg);
    }
  }

  public function delete($table, $where, $bind="") {
    $sql = "DELETE FROM " . $table . " WHERE " . $where . ";";
    $this->run($sql, $bind);
  }

  private function filter($table, $info) {
    $driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if($driver == 'sqlite') {
      $sql = "PRAGMA table_info('" . $table . "');";
      $key = "name";
    }
    elseif($driver == 'mysql') {
      $sql = "DESCRIBE " . $table . ";";
      $key = "field";
    }
    elseif($driver == 'oci') 
    {
      //$sql = "DESC ".$table."";
      $sql = "SELECT  column_name FROM user_tab_columns where TABLE_NAME = '".strtoupper($table)."'";
      $key = "column_name";
    }
    else {  
      $sql = "SELECT column_name FROM information_schema.columns WHERE table_name = '" . $table . "';";
      $key = "column_name";
    } 

    if(false !== ($list = $this->run($sql))) {
      $fields = array();
      foreach($list as $record)
          $fields[] = strtolower($record[$key]);
      return array_values(array_intersect($fields, array_keys($info)));
    }
    return array();
  }

  private function cleanup($bind) {
    if(!is_array($bind)) {
      if(!empty($bind))
        $bind = array($bind);
      else
        $bind = array();
    }
    return $bind;
  }

  public function insert($table, $info,$columkey=false) {

    if($this->getAttribute(\PDO::ATTR_DRIVER_NAME)=='oci'){
      $newinfo=array();
      foreach ($info as $key => $value) {
          $newinfo[strtoupper($key)]=$value;
      }
      $info=$newinfo;
    }
    $fields = $this->filter($table, $info);
    $sql = "INSERT INTO " . $table . " (" . implode( ", ",$fields) . ") VALUES (:" . implode( ", :",$fields) . ");";
    $bind = array();
    foreach($fields as $field)
      $bind[":$field"] = $info[$field];
    //echo $sql;var_export($bind);
    $result= $this->run($sql, $bind);
    if($result)
      return true;
    else{
      // Antes: echo $sql; die; (filtraba SQL y mataba la petición).
      error_log('[DB] insert fallido en '.$table.': '.$this->error);
      return false;
    }
  }

  public function runone($sql, $bind="") {
    $this->sql = trim($sql);
    if($this->getAttribute(\PDO::ATTR_DRIVER_NAME)=='oci'){
      $this->sql = trim(str_replace(array(";"," as "),array(""," "),$sql));
       
    }
    $this->bind = $this->cleanup($bind);
    $this->error = "";
    try {
      $pdostmt = $this->prepare($this->sql);
      if($pdostmt->execute($this->bind) !== false) {
        if(preg_match("/^(" . implode("|", array("select", "describe", "pragma")) . ") /i", $this->sql)){
          $var=$pdostmt->fetchAll(\PDO::FETCH_ASSOC);
          if (is_array($var) && count($var)>0 ) return $var[0];
          return [];
        }elseif(preg_match("/^(" . implode("|", array("delete", "insert", "update")) . ") /i", $this->sql))
          return $pdostmt->rowCount();
      }else{
        //var_export($pdostmt);
      }
    } catch (\PDOException $e) {
      error_log('[DB] '.$e->getMessage().' | '.$this->sql);
      if(defined('APP_DEBUG') && APP_DEBUG) echo $this->sql.' '.$e->getMessage();
      $this->error = $e->getMessage();
      $this->debug();
      return false;
    }
  }

  public function run($sql, $bind=[]) {
    $this->sql = trim($sql);
    if($this->getAttribute(\PDO::ATTR_DRIVER_NAME)=='oci'){
      $this->sql = trim(str_replace(array(";"," as "),array(""," "),$sql));
    }
    $this->bind = $this->cleanup($bind);
    $this->error = "";
    try {
      $pdostmt = $this->prepare($this->sql);
      if($pdostmt->execute($this->bind) !== false) {
        if(preg_match("/^(" . implode("|", array("select", "describe", "pragma")) . ") /i", $this->sql))
          return $pdostmt->fetchAll(\PDO::FETCH_ASSOC);
        elseif(preg_match("/^(" . implode("|", array("delete", "insert", "update")) . ") /i", $this->sql))
          return $pdostmt->rowCount();
      }else{
//        var_export($this->bind);
      }
    } catch (\PDOException $e) {
      error_log('[DB] '.$e->getMessage().' | '.$this->sql);
      if(defined('APP_DEBUG') && APP_DEBUG) echo $this->sql.' '.$e->getMessage();
      $this->error = $e->getMessage();
      $this->debug();
      return false;
    }
  }

  public function select($table, $where="", $bind="", $fields="*") {
    $sql = "SELECT " . $fields . " FROM " . $table;
    if(!empty($where))
      $sql .= " WHERE " . $where;
    $sql .= ";";
    return $this->run($sql, $bind);
  }

  public function setErrorCallbackFunction($errorCallbackFunction, $errorMsgFormat="html") {
    //Variable functions for won't work with language constructs such as echo and print, so these are replaced with print_r.
    if(in_array(strtolower($errorCallbackFunction), array("echo", "print")))
      $errorCallbackFunction = "print_r";

    if(function_exists($errorCallbackFunction)) {
      $this->errorCallbackFunction = $errorCallbackFunction;  
      if(!in_array(strtolower($errorMsgFormat), array("html", "text")))
        $errorMsgFormat = "html";
      $this->errorMsgFormat = $errorMsgFormat;  
    } 
  }

  public function update($table, $info, $where, $bind="") {
    $fields = $this->filter($table, $info);
    $fieldSize = sizeof($fields);

    $sql = "UPDATE " . $table . " SET ";
    for($f = 0; $f < $fieldSize; ++$f) {
      if($f > 0) $sql .= ", ";
      $sql .= $fields[$f] . " = ? " ; 
    }
    $sql .= " WHERE " . $where . ";";
    $newbind=[];
    foreach($fields as $field) $newbind[] = $info[$field];
    $bind = $this->cleanup($bind);
    foreach($bind as $field) $newbind[] = $field;
    return $this->run($sql, $newbind);
  }
} 

