<?php declare(strict_types = 1);
/**
 * Rutas del panel (/app/…). Formato de cada entrada:
 *
 *   [métodos, patrón, [ClaseScreen, método, 'perm' => 'read'|'write' (opcional)]]
 *
 * - El patrón va SIN el prefijo APP_PATH ('settings', no '/app/settings').
 * - Los parámetros {nombre:regex} llegan a la Screen como argumentos posicionales.
 * - Nivel de permiso exigido: GET/HEAD → 'read', resto → 'write' (PermissionDomain).
 *   'perm' lo sobrescribe: 'write' en un GET que muta estado, 'read' en un POST que solo filtra.
 *   Un GET que muta DEBE llevar 'perm' => 'write' y figurar en tests/Unit/RoutesPermTest.php.
 * - El módulo de permisos sale del nombre de la clase: SettingsScreen → "settings".
 */
return [
    [['GET','POST'], '', ['App\Screens\IndexScreen', 'noroute']],
    [['GET','POST'], 'index', ['App\Screens\IndexScreen', 'index']],
    [['GET','POST'], 'myprofile', ['App\Screens\IndexScreen', 'myprofile']],
    [['GET'],  'profile/2fa-setup', ['App\Screens\UserProfileScreen', 'setup2fa']],
    [['POST'], 'profile/2fa-setup', ['App\Screens\UserProfileScreen', 'setup2fa']],

    [['GET','POST'], 'login', ['App\Screens\LoginScreen', 'index']],
    // Logout solo por POST con CSRF: un GET (imagen o enlace en otra web) no puede cerrar la sesión.
    [['POST'], 'logout', ['App\Screens\LoginScreen', 'logout']],

    [['GET'], 'settings', ['App\Screens\SettingsScreen', 'index']],
    [['GET','POST'], 'settings/user/{id:\d+}', ['App\Screens\SettingsScreen', 'user']],
    [['GET','POST'], 'settings/typeuser/{id:\d+}', ['App\Screens\SettingsScreen', 'typeuser']],
];
