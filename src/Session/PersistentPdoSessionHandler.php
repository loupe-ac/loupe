<?php

declare(strict_types=1);

namespace App\Session;

/**
 * A SkipLockedPdoSessionHandler over a persistent connection. A kept connection that the
 * database closed fails the first statement, so open() checks it and connects
 * again once.
 */
final class PersistentPdoSessionHandler extends SkipLockedPdoSessionHandler
{
    #[\Override]
    public function open(string $savePath, string $sessionName): bool
    {
        parent::open($savePath, $sessionName);

        try {
            $this->getConnection()->query('SELECT 1');
        } catch (\PDOException) {
            $this->close();

            return parent::open($savePath, $sessionName);
        }

        return true;
    }
}
