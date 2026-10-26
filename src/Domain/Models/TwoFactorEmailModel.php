<?php
namespace App\Domain\Models;

use App\Core\ModelBase;

class TwoFactorEmailModel extends ModelBase
{
    public static $tablename = 'user_2fa_emails';

    public static function get($db, $userid)
    {
        return parent::get($db, $userid);
    }

    public static function insert($db, $data)
    {
        return parent::insert($db, $data);
    }

    public static function delete($db, $userid)
    {
        // Database::delete($table, string $where, $bind). Antes se pasaba un array como $where.
        $db->delete(self::$tablename, 'userid = ?', [$userid]);
    }

    public static function updateAttempts($db, $userid, $attempts)
    {
        $db->update(self::$tablename, ['attempts' => $attempts], 'userid = ?', [$userid]);
    }
}
