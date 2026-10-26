<?php
namespace App\Core;

/**
 * Envoltorio mínimo sobre $_SESSION. public/app.php ya hace session_start();
 * el constructor solo la arranca si nadie lo hizo (CLI, tests).
 */
class Session{
    public function __construct(){
      if(session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
      if(!isset($_SESSION)) $_SESSION = [];
    }
    public function set($key, $value){
        $_SESSION[$key] = $value;
    }
    public function get($key){
      if(isset($_SESSION[$key])) return $_SESSION[$key];
      return null;
    }
    /** Elimina una clave de la sesión (no destruye la sesión). */
    public function delete($key){
      unset($_SESSION[$key]);
    }
    /**
     * Destruye la sesión y vacía $_SESSION. OJO: tras destroy() la sesión ya no
     * está activa y cualquier set() posterior en la misma petición SE PIERDE.
     * Para "deshacer" un login a medias usa delete() de las claves concretas.
     */
    public function destroy(){
      $_SESSION = [];
      if(session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }
}
