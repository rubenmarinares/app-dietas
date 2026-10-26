<?php declare(strict_types = 1);
/**
 * Arranque común. Lo incluye un front controller (public/app.php); la sesión la abre
 * App (SecurityHeaders::startsession), que necesita APP_PATH. Orden importante:
 *   autoload → helpers → constants (.env) → timezone → debugbar → BD → dbschema → menú → App
 */
ini_set('memory_limit', '512M');

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Core/functions.php';
require __DIR__ . '/../src/Config/constants.php';

// Las fechas yyyymmdd (date("Ymd")) dependen de la zona horaria: fijarla siempre,
// diga lo que diga php.ini. Tras constants.php para que nada la pise.
date_default_timezone_set(APP_TIMEZONE);
if (APP_DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

use App\Core\{App,Database};
use DebugBar\StandardDebugBar;

// DebugBar solo con APP_DEBUG=1: si está activa, Database envuelve el PDO en
// TraceablePDO y el layout pinta la barra. Nunca en producción.
$debugbar = null;
$debugbarRenderer = null;
if (APP_DEBUG) {
  $debugbar = new StandardDebugBar();
  $debugbarRenderer = $debugbar->getJavascriptRenderer();
}

$db = Database::getInstance();
// dbschema.php define $dbschema en este ámbito (global). ModelBase lo lee de $GLOBALS["dbschema"].
require __DIR__ . '/dbschema.php';
// Menú lateral: $menu global, ScreenBase lo inyecta en las plantillas.
require __DIR__ . '/Menu.php';

$app = new App($db, include(__DIR__ . '/RoutesApp.php'), null);
