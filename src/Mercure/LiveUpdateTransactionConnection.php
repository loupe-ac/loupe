<?php

declare(strict_types=1);

namespace App\Mercure;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

/** DBAL calls the driver for the outermost transaction only and runs a nested one as a savepoint. */
final class LiveUpdateTransactionConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $connection,
        private readonly LiveUpdateTransactionBuffer $buffer,
    ) {
        parent::__construct($connection);
    }

    #[\Override]
    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->buffer->begin();
    }

    #[\Override]
    public function commit(): void
    {
        parent::commit();
        $this->buffer->commit();
    }

    #[\Override]
    public function rollBack(): void
    {
        parent::rollBack();
        $this->buffer->rollBack();
    }
}
