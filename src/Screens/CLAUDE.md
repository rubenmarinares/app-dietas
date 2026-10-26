# src/Screens — controladores HTML

Clases `XxxScreen extends App\Core\ScreenBase`. Una ruta = un método público. Orquestan: CSRF, sesión, llamada a Domain, formato y render.

## Lo que realmente tienes disponible

| Qué | Cómo |
|---|---|
| App | `$this->app` |
| BBDD (para pasar a Domain) | `$this->app` (Domain lee `$app->db`) o `$this->app->db` para métodos que piden `$db` |
| Usuario conectado | `$this->app->user` (array sin `password`) |
| Permisos | `$this->app->userpermissions`, `$this->app->currentmodule`; en Twig `can()` |
| Sesión | `$this->app->session->get/set/delete` |
| Request | `$this->app->request->getRealMethod()`, `->get('x')`, `->post('x')` (o `$_GET/$_POST`) |
| Mensaje flash | `$this->app->addmessage(['type'=>'success','text'=>'…'])` (se muestra en la siguiente página) |
| Redirección | `$this->app->redirect('settings')` (prefija `APP_PATH` y hace `die`) |
| Render | `$this->rendertemplate('vista.html', $data)`; `false` como 3º parámetro devuelve el HTML |
| JSON | `$this->render_json($data, $codigo)` |
| CSRF | `\App\Core\Csrf::validate($_POST['csrf_token'] ?? '')` |

No existen `$this->db`, `$this->csrf`, `currentUser` ni `layout/base.html` (la doc de admin.redgdps los mencionaba por error).

## Patrón de acción (listado + sidepanel de edición)

```php
public function page($id){
  if($_POST){
    if(\App\Core\Csrf::validate($_POST['csrf_token'] ?? '')){
      $item = $_POST; $item['idpage'] = intval($id);
      try {
        if(isset($_POST['eliminar'])) PageDomain::delete($this->app, $id);
        else PageDomain::save($this->app, $item);
      } catch(\InvalidArgumentException $e){
        $this->app->addmessage(['type'=>'danger','text'=>$e->getMessage()]);
      }
    } else $this->app->addmessage(['type'=>'danger','text'=>'El formulario ha caducado.']);
    $this->app->redirect('pages');
  }
  $item = $id==0 ? ['idpage'=>0] : PageDomain::get($this->app, $id);
  $this->rendertemplate('sidepanels/page_edit.html', ['item'=>$item]);
}
```

- Los sidepanels se cargan por AJAX en el offcanvas (`<a class="ajax-sidepanel" href="…">`); su plantilla NO extiende layout.
- El botón Eliminar es `{{ print_checkeliminar()|raw }}`: envía `name="eliminar"` solo tras marcar el check.
- Formatea en la Screen (o con filtros Twig) fechas `yyyymmdd` e importes ×10000; el Domain trabaja con valores crudos.

## Screens incluidas

| Screen | Rutas | Módulo |
|---|---|---|
| `LoginScreen` | `login` (GET/POST, fases credenciales/2FA), `logout` | pública |
| `IndexScreen` | `''` (→ index o login), `index` (dashboard de ejemplo), `myprofile` (cambio de contraseña) | `index` (siempre permitido) |
| `UserProfileScreen` | `profile/2fa-setup` GET/POST (asistente TOTP/email) | `userprofile` (siempre permitido) |
| `SettingsScreen` | `settings`, `settings/user/{id}`, `settings/typeuser/{id}` | `settings` |
