<?php
/**
 * Esquema de BBDD: fuente de verdad para ModelBase (primarias, maincol, ordercol,
 * tipos, required, length, autoincrement, default, json).
 * REGLA "solo añadir": al regenerar o editar NUNCA se pierden propiedades fijadas
 * a mano (maincol, primary, ordercol, default, type json). Ver docs/aprendizajes.md.
 * Entradas copiadas literalmente de admin.redgdps.
 */
$dbschema=[

"settings"=>[
  'key' =>['type' => 'varchar', 'required'=>true, 'primary'=>true, 'length' => '50',],
  'props' =>['type' => 'json', 'required'=>false, 'length' => '16777215',],
 ],

"typesuser"=>[
  'idtypeuser' =>['type' => 'int', 'required'=>true, 'primary'=>true, 'autoincrement'=>true,],
  'typeuser' =>['type' => 'varchar', 'maincol' => true, 'required'=>true, 'length' => '250',],
  'permissions' =>['type' => 'json', 'required'=>false,],
 ],

"users"=>[
  'iduser' =>['type' => 'int', 'required'=>true, 'primary'=>true, 'autoincrement'=>true,],
  'name' =>['type' => 'varchar', 'required'=>true, 'maincol'=>true, 'length' => '250',],
  'surname' =>['type' => 'varchar', 'required'=>true, 'length' => '250',],
  'email' =>['type' => 'varchar', 'required'=>true, 'length' => '250',],
  'password' =>['type' => 'varchar', 'required'=>false, 'length' => '250',],
  'status' =>['type' => 'int', 'required'=>true,'default'=>0,],
  'created' =>['type' => 'int', 'required'=>true,'default'=>0,],
  'updated' =>['type' => 'int', 'required'=>true,'default'=>0,],
  'typeuserid' =>['type' => 'int', 'required'=>true,'default'=>0,],
  'deadline2fa' =>['type' => 'int', 'required'=>false,],
 ],

"users_logs"=>[
  'idlog' =>['type' => 'int', 'required'=>true, 'primary'=>true, 'autoincrement'=>true,],
  'userid' =>['type' => 'int', 'required'=>false,],
  'action' =>['type' => 'varchar', 'required'=>false, 'length' => '45',],
  'stamp' =>['type' => 'int', 'required'=>false,],
 ],

'user_2fa' => [
    'userid' => ['type' => 'int', 'primary' => true],
    'primary_method' => ['type' => 'varchar', 'length' => 10, 'required' => true],
    'backup_method' => ['type' => 'varchar', 'length' => 10, 'required' => true],
    'totp_secret' => ['type' => 'varchar', 'length' => 128],
    'backup_email' => ['type' => 'varchar', 'length' => 255],
    'configured_at' => ['type' => 'int', 'required' => true],
],

'user_2fa_emails' => [
    'userid' => ['type' => 'int', 'primary' => true],
    'code' => ['type' => 'varchar', 'length' => 10, 'required' => true],
    'created_at' => ['type' => 'int', 'required' => true],
    'attempts' => ['type' => 'int'],
],
];
