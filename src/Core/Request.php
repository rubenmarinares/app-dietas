<?php 
namespace App\Core;
class Request{
    public $method;
    public $uri;
    public $params;
    public $body;
    public $headers;
    public $postparams;
    public $getparams;
    public function __construct(){
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->params = $_REQUEST;
        $this->body = file_get_contents('php://input');
        // getallheaders() no existe en CLI ni en algunos SAPI.
        $this->headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $this->postparams=$_POST;
        $this->getparams=$_GET;
    }

    public function is_post(){
        return $this->method == 'POST';
    }

    /**
     * Método efectivo. Solo se acepta X-HTTP-Method-Override sobre un POST y hacia
     * PUT/PATCH/DELETE: nunca se permite "rebajar" un POST a GET/HEAD, porque el gate
     * de permisos de App exigiría solo lectura y la Screen procesaría el $_POST.
     */
    public function getRealMethod(){
        $override = strtoupper((string)($this->headers['X-HTTP-Method-Override'] ?? ''));
        if($this->method === 'POST' && in_array($override, ['PUT','PATCH','DELETE'], true)){
            return $override;
        }
        return $this->method;
    }
    public function getPathInfo(){
        $path = parse_url($this->uri, PHP_URL_PATH);
        return $path;
    }

    public function get($key=null){
        if(!$key) return $this->getparams;
        return $this->getparams[$key] ?? null;
    }
    public function post($key=false){
        if(!$key) return $this->postparams;
        return $this->postparams[$key] ?? null;
    }


    public function getMethod(){
        return $this->method;
    }
    public function getUri(){
        return $this->uri;
    }
    public function getParams(){
        return $this->params;
    }
    public function getBody(){
        return $this->body;
    }
    public function getHeaders(){
        return $this->headers;
    }
    public function getHeader($name){
        return $this->headers[$name];
    }
    public function getParam($name){
        return $this->params[$name];
    }
    public function getBodyParam($name){
        $body = json_decode($this->body, true);
        return $body[$name];
    }
    public function getBodyParams(){
        return json_decode($this->body, true);
    }
    public function getBodyParamAsArray($name){
        $body = json_decode($this->body, true);
        return explode(',', $body[$name]);
    }
    public function getBodyParamAsInt($name){
        $body = json_decode($this->body, true);
        return (int)$body[$name];
    }
    public function getBodyParamAsFloat($name){
        $body = json_decode($this->body, true);
        return (float)$body[$name];
    }
    public function getBodyParamAsBool($name){
        $body = json_decode($this->body, true);
        return (bool)$body[$name];
    }
    public function getBodyParamAsDate($name){
        $body = json_decode($this->body, true);
        return new \DateTime($body[$name]);
    }
    public function getBodyParamAsDateTime($name){
        $body = json_decode($this->body, true);
        return new \DateTime($body[$name]);
    }
    public function getBodyParamAsTime($name){
        $body = json_decode($this->body, true);
        return new \DateTime($body[$name]);
    }
    public function getBodyParamAsTimestamp($name){
        $body = json_decode($this->body, true);
        return new \DateTime($body[$name]);
    }
    public function getBodyParamAsTimestampTz($name){
        $body = json_decode($this->body, true);
        return new \DateTime($body[$name]);
    }


}
