<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use Doctrine\DBAL\Connection;
use Mcp\Server\Session\Psr16SessionStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Uid\Uuid;

final class PostgresSessionStoreTest extends KernelTestCase
{
    public function test_a_session_written_by_one_store_is_read_by_another(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $old = $this->store($connection);
        $new = $this->store($connection);
        $id = Uuid::v4();

        self::assertFalse($new->exists($id));
        self::assertTrue($old->write($id, '{"client":"claude"}'));

        self::assertTrue($new->exists($id));
        self::assertSame('{"client":"claude"}', $new->read($id));
    }

    private function store(Connection $connection): Psr16SessionStore
    {
        return new Psr16SessionStore(new Psr16Cache(new DoctrineDbalAdapter($connection, 'mcp-store-test')), 'mcp-', 86400);
    }
}
