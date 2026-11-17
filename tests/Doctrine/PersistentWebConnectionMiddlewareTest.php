<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\PersistentWebConnectionDriver;
use App\Doctrine\PersistentWebConnectionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use PHPUnit\Framework\TestCase;

final class PersistentWebConnectionMiddlewareTest extends TestCase
{
    public function test_it_leaves_the_driver_alone_outside_php_fpm(): void
    {
        $driver = $this->createStub(Driver::class);

        self::assertSame($driver, new PersistentWebConnectionMiddleware('cli')->wrap($driver));
    }

    public function test_it_wraps_the_driver_under_php_fpm(): void
    {
        $driver = $this->createStub(Driver::class);

        self::assertInstanceOf(PersistentWebConnectionDriver::class, new PersistentWebConnectionMiddleware('fpm-fcgi')->wrap($driver));
    }

    public function test_it_asks_for_a_named_persistent_connection_and_resets_it(): void
    {
        $statements = [];
        $connection = $this->createStub(DriverConnection::class);
        $connection->method('exec')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 0;
        });

        $driver = $this->createMock(Driver::class);
        $driver->expects($this->once())->method('connect')->with([
            'host' => 'db',
            'charset' => 'utf8',
            'persistent' => true,
            'application_name' => 'loupe-web',
        ])->willReturn($connection);

        self::assertSame($connection, new PersistentWebConnectionDriver($driver)->connect(['host' => 'db', 'charset' => 'utf8']));
        self::assertSame(['DISCARD ALL', "SET NAMES 'utf8'"], $statements);
    }

    public function test_it_connects_again_once_when_the_kept_connection_is_dead(): void
    {
        $dead = $this->createStub(DriverConnection::class);
        $dead->method('exec')->willThrowException(new class('server closed the connection unexpectedly') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });
        $fresh = $this->createStub(DriverConnection::class);

        $driver = $this->createMock(Driver::class);
        $driver->expects($this->exactly(2))->method('connect')->willReturnOnConsecutiveCalls($dead, $fresh);

        self::assertSame($fresh, new PersistentWebConnectionDriver($driver)->connect([]));
    }

    public function test_it_lets_a_second_failure_through(): void
    {
        $dead = $this->createStub(DriverConnection::class);
        $dead->method('exec')->willThrowException(new class('gone') extends \Exception implements DriverException {
            public function getSQLState(): ?string
            {
                return null;
            }
        });

        $driver = $this->createMock(Driver::class);
        $driver->expects($this->exactly(2))->method('connect')->willReturn($dead);

        $this->expectException(DriverException::class);

        new PersistentWebConnectionDriver($driver)->connect([]);
    }
}
