<?php declare(strict_types = 1);
/**
 * Front controller del panel (/app/…). .htaccess reescribe /app/* aquí.
 * En desarrollo: php -S localhost:8000 public/router.php (ver README).
 */
// Errores: visibles solo si APP_DEBUG=1 (se reajusta tras cargar constants en Bootstrap).
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);

// La sesión la arranca App (SecurityHeaders::startsession: cookie HttpOnly/SameSite/Secure y modo estricto).

require __DIR__ . '/../src/Bootstrap.php';
