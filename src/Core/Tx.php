<?php declare(strict_types=1);

namespace App\Core;

/**
 * Transacción alrededor de un bloque. Sin anidamiento real: si ya hay una abierta, el bloque se une a ella.
 * Mientras dura, un `db` lanza en cualquier error SQL (`throwonerror`): un fallo de escritura deshace todo
 * aunque quien llama no compruebe el `false` de un Model.
 */
final class Tx
{
    static public function run(\PDO $db, callable $fn): mixed
    {
        $isdb = $db instanceof db;
        $previous = $isdb ? $db->throwonerror : null;
        if ($isdb) $db->throwonerror = true;
        try {
            if ($db->inTransaction()) return $fn();
            $db->beginTransaction();
            try {
                $result = $fn();
                $db->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        } finally {
            if ($isdb) $db->throwonerror = $previous;
        }
    }
}
