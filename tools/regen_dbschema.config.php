<?php declare(strict_types=1);
/**
 * Overrides para tools/regen_dbschema.php (sin secretos: solo metadatos de esquema).
 *
 * PKs y autoincrement se autodetectan desde INFORMATION_SCHEMA. Estos valores solo se
 * aplican a columnas/propiedades NUEVAS: el dbschema actual nunca se sobrescribe.
 *
 *   - primary:       { table => [col1, col2, ...] }   fuerza PK (raro)
 *   - maincol:       { table => col }                 columna representativa (combos, orden)
 *   - json:          { table => [col1, ...] }         columnas TEXT que guardan JSON
 *   - ignore_tables: [ table, ... ]                   tablas que no deben aparecer en dbschema
 */
return [
    'primary' => [],
    'maincol' => [
        'users'     => 'name',
        'typesuser' => 'typeuser',
    ],
    'json' => [
        'typesuser' => ['permissions'],
        'settings'  => ['props'],
    ],
    'ignore_tables' => [],
];
