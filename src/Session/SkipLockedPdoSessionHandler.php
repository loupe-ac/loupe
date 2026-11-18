<?php

declare(strict_types=1);

namespace App\Session;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * A PdoSessionHandler whose expiry update never waits for a row lock. A read-only
 * request extends the session it read while a locking request can hold that row, and
 * waiting would undo the point of reading with no lock. A skipped update loses nothing,
 * because the request that holds the lock extends the expiry when it writes.
 */
class SkipLockedPdoSessionHandler extends PdoSessionHandler
{
    #[\Override]
    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        if ('' === $data) {
            return parent::updateTimestamp($sessionId, $data);
        }

        $expiry = time() + (int) \ini_get('session.gc_maxlifetime');

        $statement = $this->getConnection()->prepare(
            'UPDATE sessions SET sess_lifetime = :expiry, sess_time = :time WHERE sess_id = (SELECT sess_id FROM sessions WHERE sess_id = :id FOR UPDATE SKIP LOCKED)'
        );
        $statement->bindValue(':id', $sessionId);
        $statement->bindValue(':expiry', $expiry, \PDO::PARAM_INT);
        $statement->bindValue(':time', time(), \PDO::PARAM_INT);
        $statement->execute();

        return true;
    }
}
