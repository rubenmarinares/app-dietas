<?php
namespace App\Domain\Models;

use App\Core\ModelBase;

class TwoFactorModel extends ModelBase
{
    public static $tablename = 'user_2fa';

    public static function get($db, $userid)
    {
        return parent::get($db, $userid);
    }

    public static function insert($db, $data)
    {
        return parent::insert($db, $data);
    }

    public static function update($db, $data, $where = [], $bind = [])
    {
        return parent::update($db, $data, $where, $bind);
    }

    public static function delete($db, $userid)
    {
        $db->delete(self::$tablename, 'userid = ?', [$userid]);
    }

    public static function hasUser($db, $userid)
    {
        $result = parent::get($db, $userid);
        return !empty($result);
    }
}
