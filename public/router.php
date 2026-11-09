<?php
/**
 * Router para el servidor embebido de PHP (solo desarrollo), emula el .htaccess:
 *   php -S localhost:8000 -t public public/router.php
 * - /assets/... y ficheros existentes en public/ se sirven tal cual.
 * - /app/...  → public/app.php
 * - resto     → public/index.php
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false; // el servidor embebido sirve el estático
}
if ($path === '/app') { header('Location: /app/'); return true; }
if (str_starts_with($path, '/app/')) {
    require __DIR__ . '/app.php';
    return true;
}
require __DIR__ . '/index.php';
return true;
