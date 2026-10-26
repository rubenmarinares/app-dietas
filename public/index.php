<?php declare(strict_types = 1);
/**
 * Raíz del sitio. Hoy solo redirige al panel; aquí irá el front controller
 * PÚBLICO del CMS (con su propio fichero de rutas y screens en App::PUBLICSCREENS).
 */
require __DIR__ . '/../src/Config/constants.php';
header('Location: ' . APP_PATH);
exit;
