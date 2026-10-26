<?php

namespace App\Core;

use App\Core;

/**
 * Contenedor de la petición. El constructor HACE TODA la petición:
 * sesión → request → dispatch FastRoute → gate de autenticación → gate de
 * permisos → invocación de Screen::metodo(...$vars).
 *
 * No hay middleware: cualquier regla transversal (auth, permisos, pantallas
 * públicas) vive aquí. Ver src/Core/CLAUDE.md.
 */
class App
{
  public $logado = false;
  public $params=[];
  public $request;
  public $session;
  public $user;
  public $response;
  public $db;
  public $logger;
  public $routes;
  public $messages = [];
  public ?array $userpermissions = null;
  public string $currentmodule = '';

  /**
   * Screens accesibles SIN sesión. LoginScreen es pública por definición.
   * Añade aquí (FQCN) las pantallas públicas del futuro CMS (portada, páginas,
   * formularios de contacto…). Una screen pública NO pasa por el gate de
   * permisos: $this->user será null y can() devolverá acceso total
   * (permisos null), así que no expongas acciones de escritura en ellas.
   */
  public const PUBLICSCREENS = [
    \App\Screens\LoginScreen::class,
  ];

  function __construct($db, $routes, $logger)
  {
    $this->session=new Core\Session();
    if( $this->session->get("logado")){
      $this->logado = true;
      $this->user = $this->session->get("user");
    }
    if( $this->session->get("app_messages")) $this->messages = json_decode($this->session->get("app_messages"),true) ?: [];
    $this->request = new Core\Request();

    // Los Domain reciben $app y leen $app->db: debe estar asignado antes de despachar.
    $this->db = $db;
    $this->logger = $logger;
    $this->routes = $routes;
    $dispatcher = \FastRoute\simpleDispatcher(function (\FastRoute\RouteCollector $r) {
      foreach ($this->routes as $route) {
        $r->addRoute($route[0], $route[1], $route[2]);
      }
    });

    // Las rutas se declaran SIN el prefijo APP_PATH (p. ej. 'settings/user/{id:\d+}').
    $path = ''.$this->request->getPathInfo();
    if (str_starts_with($path, APP_PATH)) $path = substr($path, strlen(APP_PATH));
    $routeInfo = $dispatcher->dispatch($this->request->getRealMethod(), $path);
    switch ($routeInfo[0]) {
      case \FastRoute\Dispatcher::NOT_FOUND:
        $this->render_json(['error' =>"404 - Page not found",'route'=>$path],404);
        exit;
      case \FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $this->render_json(['error' =>"405 - Method not allowed"],405);
        exit;
      case \FastRoute\Dispatcher::FOUND:
        $className = $routeInfo[1][0];
        $method = $routeInfo[1][1];
        $vars = $routeInfo[2];
        $ispublic = in_array($className, self::PUBLICSCREENS, true);

        // Gate 1: autenticación.
        if (!$ispublic && !$this->logado) {
          header("Location: ".APP_PATH."login");
          die;
        }

        // Gate 2: permisos por módulo (typesuser.permissions). Solo para screens privadas.
        if (!$ispublic) {
          $this->currentmodule = \App\Domain\PermissionDomain::modulefromclass($className);
          $this->userpermissions = \App\Domain\PermissionDomain::permissionsforuser($db, $this->user);
          // Override por ruta: ['Clase','metodo','perm'=>'read'] declara POSTs de solo lectura (ej. filtros)
          // y ['Clase','metodo','perm'=>'write'] declara GETs que mutan estado.
          $requiredlevel = (is_array($routeInfo[1]) && isset($routeInfo[1]['perm']))
            ? $routeInfo[1]['perm']
            : \App\Domain\PermissionDomain::requiredlevelformethod($this->request->getRealMethod());
          if (!\App\Domain\PermissionDomain::isalwaysallowed($this->currentmodule)
              && !\App\Domain\PermissionDomain::can($this->userpermissions, $this->currentmodule, $requiredlevel)) {
            \App\Domain\PermissionDomain::logdenied($db, $this->user);
            $wantsjson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
              || (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0);
            if ($wantsjson) {
              $this->render_json(['error' => '403 - Acceso denegado'], 403);
            } else {
              http_response_code(403);
              echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>403</title></head><body style="font-family:sans-serif;text-align:center;padding-top:4rem"><h1>403 — Acceso denegado</h1><p>No tienes permisos para realizar esta acción.</p><p><a href="'.APP_PATH.'">Volver</a></p></body></html>';
            }
            exit;
          }
        }

        $class = new $className($this);
        try {
          call_user_func_array([$class,$method],$vars);
        }catch(\Exception $e){
          $this->render_json(['error' =>"500 - Internal Server Error: ".$e->getMessage()],500);
        }
        break;
    }
  }
  public function setparam($key,$value){
    $this->params[$key]=$value;
  }
  public function getparam($key,$default=null){
    return $this->params[$key]??$default;
  }

  public function debug($message){
    if($GLOBALS["debugbar"]??false){
      $GLOBALS["debugbar"]['messages']->addMessage($message);
    }
  }

  /**
   * Mensaje flash para la siguiente página renderizada. Acepta
   * ['type'=>'success|info|warning|danger|error','text'=>'...'] o un string
   * (se trata como warning). ScreenBase::rendertemplate los consume una vez.
   */
  function addmessage($message){
    if (!is_array($message)) $message = ['type' => 'warning', 'text' => (string)$message];
    $this->messages[]=$message;
    $this->session->set("app_messages",json_encode($this->messages));
  }

  function cleanmessages(){
    $this->messages=[];
    $this->session->set("app_messages",json_encode($this->messages));
  }

  function isLogged(){
    return $this->logado;
  }
  function request()
  {
    return $this->request;
  }

  /** Abre sesión para $item (fila de users). Nunca guarda el hash de la contraseña en sesión. */
  function setUser($item){
    unset($item['password']);
    // Evita fijación de sesión: id nuevo al pasar a autenticado.
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) session_regenerate_id(true);
    $this->session->set("logado",true);
    $this->session->set("userid",$item['iduser']);
    $this->session->set("user",$item);
    $this->logado = true;
    $this->user = $item;
  }

  function logout(){
    $this->session->destroy();
    $this->logado = false;
    $this->user = null;
    header("Location: ".APP_PATH."login");
  }

  function redirect($url){
    header("Location: ".APP_PATH.$url);
    die;
  }

  function render_json($data,$code=200)
  {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data);
  }
}
