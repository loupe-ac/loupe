<?php

declare(strict_types=1);

namespace App\Mercure;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

#[AsMiddleware]
final readonly class LiveUpdateTransactionMiddleware implements Middleware
{
    public function __construct(
        private LiveUpdateTransactionBuffer $buffer,
    ) {
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new LiveUpdateTransactionDriver($driver, $this->buffer);
    }
}
