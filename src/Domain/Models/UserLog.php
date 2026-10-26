<?php declare(strict_types=1);

namespace App\Domain\Models;

use App\Core;

class UserLog extends Core\ModelBase
{
    public static $tablename = 'users_logs';

    public static function record($db, int $userId, string $action, ?string $stamp = null): void
    {
        $stamp = $stamp ?? date('Ymd');
        $db->run("INSERT INTO users_logs (userid, action, stamp) VALUES (?, ?, ?)", [$userId, $action, $stamp]);
    }
}
