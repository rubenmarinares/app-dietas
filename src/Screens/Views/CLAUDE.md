# src/Screens/Views — plantillas Twig

Tema Bootstrap 5 (Able Pro) en `public/assets/`. jQuery, DataTables, Select2, jquery-validate, TinyMCE, Datepicker y Dropzone se cargan en `layout/head.html`.

## Estructura

| Fichero | Uso |
|---|---|
| `layout.html` | Layout único. Con sesión (`logado`): sidebar + header + contenido + footer. Sin sesión: caja `auth-main` (login). Pinta los mensajes flash **fuera** del `block content`. |
| `layout/head.html` | Metas (`APP_NAME`), CSS/JS y estilos propios. |
| `layout/header.html` | Barra superior: colapsar menú y desplegable de usuario (Mi perfil, Salir). |
| `layout/sidebarmenu.html` | Menú desde `menu` (`src/Menu.php`); oculta entradas con `module` sin permiso de lectura. Activa la entrada `imenu1`. |
| `layout/footer.html` | Pie con `APP_NAME`. |
| `layout/foot.html` | JS común: `renderplugins()` (validate, select2, tinymce `.htmleditor`, datepicker `.pc-datepicker`, checkeliminar, dropzone), `.ajax-sidepanel`, `.dataTable` y el offcanvas `#sidepanel`. |
| `login.html`, `index.html`, `myprofile.html`, `settings.html` | Pantallas. |
| `profile/setup_2fa_*.html` | Asistente 2FA. |
| `sidepanels/*.html` | Formularios del offcanvas (sin `extends`). |

## Página nueva

```twig
{% extends "layout.html" %}
{% set imenu1=1 %}   {# clave de la entrada en Menu.php #}
{% block content %}
<div class="page-header d-print-none"> … <div class="page-pretitle">Título</div> … </div>
<div class="page-body">
  <div class="row">
    <div class="col-md-6">
      <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between">
          <h4 class="card-title"><i class="fas fa-file"></i> Título</h4>
        </div>
        <div class="card-body"> … </div>
      </div>
    </div>
  </div>
</div>
{% endblock %}
```

## Formularios

- Siempre `<input type="hidden" name="csrf_token" value="{{ csrf_token }}">`.
- Botón guardar dentro de `{% if can() %}`; borrar con `{{ print_checkeliminar()|raw }}`.
- Selects: comparar con `==` (`{% if opt.id == item.campo %}`), no con `in` (`"1" in "11"` es true).
- `form-control required` activa jquery-validate.

## Filtros propios

`monedaatexto` (12345000 → 1.234,50), `monedaatextous`, `monedaaentero` ("1.234,5" → 12345000), `dateinttofrontend` (20261020 → 20/10/2026), `datesqltofrontend`, `datetoint`, `datesqltoint`, `json_decode`, `truncate(n)`, `filesizetotext`, `urlencodepreserveslash`.

## Continuidad de diseño

No simplificar cards, filas/columnas ni clases existentes al editar. Antes de reescribir una plantilla: `git log -p -- fichero`. Comprobar con `php tools/twig_lint.php`.
