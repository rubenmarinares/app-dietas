<?php
/**
 * Menú lateral (layout/sidebarmenu.html). Global $menu; ScreenBase lo inyecta como "menu".
 *
 * Cada entrada: ['label', 'icon' (Font Awesome), 'url' (relativa a APP_PATH),
 *                'module' (opcional: se oculta si el usuario no tiene ni lectura),
 *                'submenu' (opcional: lista de ['label','icon','url'])]
 * La clave numérica se compara con {% set imenu1=N %} de cada plantilla para marcar la activa.
 */
$menu = [
  /* Ejemplo de entrada con submenú para el CMS:
  1=> [ 'label'=> 'Contenidos', 'icon'=> 'fas fa-file-lines', 'url'=> 'pages', 'module'=> 'pages'
    ,'submenu'=> [
      ['label'=> 'Páginas', 'icon'=> 'fas fa-file', 'url'=> 'pages' ],
      ['label'=> 'Noticias', 'icon'=> 'fas fa-newspaper', 'url'=> 'news' ],
    ],
  ],
  */
  9=> [ 'label'=> 'Configuración', 'icon'=> 'fas fa-cogs', 'url'=> 'settings', 'module'=> 'settings' ],
];
