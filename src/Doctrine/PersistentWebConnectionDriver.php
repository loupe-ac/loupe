<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Keeps the web connection open across requests. PHP rolls back an open
 * transaction at the end of a request but leaves session-level state, such as
 * an advisory lock, on the kept connection. The reset clears it when the
 * request first connects. A kept connection that the database closed fails the
 * reset, so the driver connects again once.
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class PersistentWebConnectionDriver extends AbstractDriverMiddleware
{
    public const string APPLICATION_NAME = 'loupe-web';

    public function __construct(Driver $driver)
    {
        parent::__construct($driver);
    }

    /**
     * @param Params $params
     */
    #[\Override]
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        $params['persistent'] = true;
        $params['application_name'] = self::APPLICATION_NAME;

        try {
            return $this->reset(parent::connect($params), $params);
        } catch (DriverException) {
            return $this->reset(parent::connect($params), $params);
        }
    }

    /**
     * @param Params $params
     */
    private function reset(DriverConnection $connection, #[\SensitiveParameter] array $params): DriverConnection
    {
        $connection->exec('DISCARD ALL');

        // DISCARD ALL drops client_encoding, which the driver set at connect.
        if (isset($params['charset'])) {
            $connection->exec('SET NAMES \''.$params['charset'].'\'');
        }

        return $connection;
    }
}
