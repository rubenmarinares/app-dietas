<?php
namespace App\Domain\Models;
use App\Core;

/**
 * Ajustes clave/valor: settings(key PK varchar, props JSON).
 * `key` es palabra reservada en MySQL: siempre cualificar (settings.key) o entrecomillar.
 * Antes la propiedad se llamaba $default_order (ModelBase lee $defaultorder) y el
 * fallback generaba "ORDER BY key DESC" → error de sintaxis.
 */
class Setting extends Core\ModelBase {
  static public $tablename='settings';
  static public $defaultorder='settings.`key` ASC';
}
