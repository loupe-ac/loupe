<?php

declare(strict_types=1);

namespace App\Mercure;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class LiveUpdateTransactionDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly LiveUpdateTransactionBuffer $buffer,
    ) {
        parent::__construct($driver);
    }

    /**
     * @param Params $params
     */
    #[\Override]
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        return new LiveUpdateTransactionConnection(parent::connect($params), $this->buffer);
    }
}
