<?php

declare(strict_types=1);

namespace App\Tests\Mercure;

use App\Mercure\LiveUpdateTransactionBuffer;
use App\Mercure\LiveUpdateTransactionMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use PHPUnit\Framework\TestCase;

final class LiveUpdateTransactionMiddlewareTest extends TestCase
{
    public function test_it_tells_the_buffer_about_the_outermost_transaction(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $inner = $this->createMock(Connection::class);
        $inner->expects($this->once())->method('beginTransaction');
        $inner->expects($this->once())->method('commit');
        $connection = $this->connect($buffer, $inner);

        $connection->beginTransaction();
        self::assertTrue($buffer->isOpen());
        $buffer->hold('https://loupe.test/a', '{}');
        $connection->commit();

        self::assertFalse($buffer->isOpen());
        self::assertCount(1, $buffer->takeCommitted());
    }

    public function test_a_rollback_drops_what_the_buffer_holds(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $inner = $this->createMock(Connection::class);
        $inner->expects($this->once())->method('rollBack');
        $connection = $this->connect($buffer, $inner);

        $connection->beginTransaction();
        $buffer->hold('https://loupe.test/a', '{}');
        $connection->rollBack();

        self::assertFalse($buffer->isOpen());
        self::assertSame([], $buffer->takeCommitted());
    }

    public function test_a_begin_the_driver_refuses_leaves_the_buffer_closed(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $inner = $this->createStub(Connection::class);
        $inner->method('beginTransaction')->willThrowException(new \RuntimeException('no connection'));
        $connection = $this->connect($buffer, $inner);

        try {
            $connection->beginTransaction();
            self::fail('The refused begin must reach the caller.');
        } catch (\RuntimeException) {
            self::assertFalse($buffer->isOpen());
        }
    }

    public function test_a_commit_the_driver_refuses_keeps_the_transaction_open(): void
    {
        $buffer = new LiveUpdateTransactionBuffer();
        $inner = $this->createStub(Connection::class);
        $inner->method('commit')->willThrowException(new \RuntimeException('lost'));
        $connection = $this->connect($buffer, $inner);
        $connection->beginTransaction();
        $buffer->hold('https://loupe.test/a', '{}');

        try {
            $connection->commit();
            self::fail('The refused commit must reach the caller.');
        } catch (\RuntimeException) {
            self::assertTrue($buffer->isOpen());
            self::assertSame([], $buffer->takeCommitted());
        }
    }

    private function connect(LiveUpdateTransactionBuffer $buffer, Connection $inner): Connection
    {
        $driver = $this->createStub(Driver::class);
        $driver->method('connect')->willReturn($inner);

        return new LiveUpdateTransactionMiddleware($buffer)->wrap($driver)->connect([]);
    }
}
