<?php

declare(strict_types=1);

namespace App\Tests\Session;

use App\Observability\RequestTimeline;
use App\Session\PdoSessionHandlerFactory;
use App\Session\PersistentPdoSessionHandler;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

final class PdoSessionHandlerFactoryTest extends TestCase
{
    public function test_it_takes_the_database_name_from_doctrine_rather_than_the_url(): void
    {
        // The regression this guards: doctrine.yaml appends a dbname_suffix per
        // worktree and under PHPUnit, so DATABASE_URL names `app` while the
        // sessions table was migrated into `app_wt_feature`. Building the
        // handler from the raw URL makes every worktree share the main
        // database's sessions — silently, because that table does exist there.
        $factory = $this->factory('app_wt_feature', 'postgresql://app:s3cret@database:5432/app?serverVersion=16&charset=utf8');

        self::assertSame('pgsql:host=database;port=5432;dbname=app_wt_feature;application_name=loupe-session', $factory->sessionDsn());
    }

    public function test_it_carries_sslmode_onto_the_session_connection(): void
    {
        // A managed Postgres that requires TLS would otherwise refuse only the
        // session connection, while Doctrine and the cache connect fine.
        $factory = $this->factory('app', 'postgresql://app:s3cret@db.example.com:25060/app?sslmode=require&serverVersion=16');

        self::assertSame('pgsql:host=db.example.com;port=25060;dbname=app;sslmode=require;application_name=loupe-session', $factory->sessionDsn());
    }

    public function test_it_drops_doctrine_only_parameters_that_libpq_would_reject(): void
    {
        $factory = $this->factory('app', 'postgresql://database/app?serverVersion=16&charset=utf8');

        self::assertSame('pgsql:host=database;dbname=app;application_name=loupe-session', $factory->sessionDsn());
    }

    public function test_it_rejects_a_url_it_cannot_read(): void
    {
        $factory = $this->factory('app', 'not-a-url');

        $this->expectException(\LogicException::class);

        $factory->sessionDsn();
    }

    public function test_it_rejects_a_database_that_is_not_postgres(): void
    {
        $factory = $this->factory('app', 'mysql://app@database:3306/app');

        $this->expectException(\LogicException::class);

        $factory->sessionDsn();
    }

    public function test_it_names_the_session_connection_whatever_the_url_says(): void
    {
        $factory = $this->factory('app', 'postgresql://database/app?application_name=other');

        self::assertSame('pgsql:host=database;dbname=app;application_name=loupe-session', $factory->sessionDsn());
    }

    public function test_it_keeps_the_connection_persistent_only_under_php_fpm(): void
    {
        $url = 'postgresql://app:s3cret@database:5432/app';

        $fpm = $this->factory('app', $url, 'fpm-fcgi')->create(PdoSessionHandler::LOCK_NONE);
        $cli = $this->factory('app', $url, 'cli')->create(PdoSessionHandler::LOCK_NONE);

        self::assertInstanceOf(PersistentPdoSessionHandler::class, $fpm);
        self::assertNotInstanceOf(PersistentPdoSessionHandler::class, $cli);
    }

    private function factory(string $database, string $databaseUrl, string $sapi = 'cli'): PdoSessionHandlerFactory
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabase')->willReturn($database);

        return new PdoSessionHandlerFactory($connection, $databaseUrl, new RequestStack(), new RequestTimeline($this->createStub(HubInterface::class)), $sapi);
    }
}
