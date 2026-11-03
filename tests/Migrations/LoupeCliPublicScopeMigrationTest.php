<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261102000500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261102000500.php';

/** Runs the migration against the loupe-cli row, inside the test's own transaction. */
final class LoupeCliPublicScopeMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->connection = $em->getConnection();
    }

    public function test_up_adds_the_public_scope_to_the_row_the_earlier_migration_wrote(): void
    {
        $this->setScopes('agent mcp projects site-review');

        $this->migrate('up');

        self::assertSame('agent mcp projects site-review public', $this->scopes());
    }

    public function test_up_keeps_a_row_an_operator_edited(): void
    {
        $this->setScopes('agent');

        $this->migrate('up');

        self::assertSame('agent', $this->scopes());
    }

    public function test_down_restores_the_earlier_scopes(): void
    {
        $this->setScopes('agent mcp projects site-review public');

        $this->migrate('down');

        self::assertSame('agent mcp projects site-review', $this->scopes());
    }

    private function setScopes(string $scopes): void
    {
        self::assertSame(1, $this->connection->executeStatement(
            "UPDATE oauth2_client SET scopes = :scopes WHERE identifier = 'loupe-cli'",
            ['scopes' => $scopes],
        ));
    }

    private function scopes(): string
    {
        return (string) $this->connection->fetchOne("SELECT scopes FROM oauth2_client WHERE identifier = 'loupe-cli'");
    }

    private function migrate(string $direction): void
    {
        $migration = new Version20261102000500($this->connection, new NullLogger());
        'up' === $direction ? $migration->up(new Schema()) : $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
