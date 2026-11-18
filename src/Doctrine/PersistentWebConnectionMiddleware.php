<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Runs before ConnectTimingMiddleware (priority 0) in the wrap order, so the
 * `db.connect` span still covers the connect and the reset together.
 */
#[AsMiddleware(priority: 10)]
final readonly class PersistentWebConnectionMiddleware implements Middleware
{
    public function __construct(
        private string $sapi = \PHP_SAPI,
    ) {
    }

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return 'fpm-fcgi' === $this->sapi ? new PersistentWebConnectionDriver($driver) : $driver;
    }
}
