<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Workflow\Contract\CardDirectory;
use App\Module\Workflow\Contract\Unreadable;
use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Mark\MarkWorld;
use App\Tests\Module\Workflow\Mark\RawRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class FactSourceTablesTest extends KernelTestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function sources(): iterable
    {
        self::bootKernel();
        $container = self::getContainer();
        $providers = $container->get(FactProviders::class);
        self::assertInstanceOf(FactProviders::class, $providers);
        foreach ($providers->byClass as $provider) {
            yield $provider::class => [$provider::class, $provider->sourceTables()];
        }
        $builder = $container->get(FactsBuilder::class);
        self::assertInstanceOf(FactsBuilder::class, $builder);

        yield FactsBuilder::class => [FactsBuilder::class, $builder->sourceTables()];
    }

    /** @param list<string> $tables */
    #[DataProvider('sources')]
    #[Test]
    public function every_source_names_existing_tables_in_order(string $source, array $tables): void
    {
        self::assertNotSame([], $tables, $source.' reads no table.');
        $sorted = array_values(array_unique($tables));
        sort($sorted);
        self::assertSame($sorted, $tables, $source.' lists its tables sorted, once each.');

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $existing = $connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()');
        self::assertSame([], array_values(array_diff($tables, $existing)), $source.' names a table that does not exist.');
    }

    #[Test]
    public function every_table_a_build_reads_is_declared(): void
    {
        $reads = $this->tablesReadByBuilds();

        self::assertContains('board_card_pull_requests', $reads, 'Guard: the builds read the tables of a card with a pull request.');
        self::assertContains('bridge_worker_runs', $reads, 'Guard: the builds read the tables of a card with a worker run.');
        self::assertContains('inbox_asks', $reads, 'Guard: the builds read the tables of a card with an ask.');
        self::assertContains('board_card_documents', $reads, 'Guard: the builds read the tables of a card with a document.');
        self::assertContains('oauth_refresh_token_login', $reads, 'Guard: the builds read the login of a quiet bridge.');
        self::assertContains('board_card_links', $reads, 'Guard: the builds read the tables of a card with a blocker.');
        self::assertSame([], array_values(array_diff($reads, $this->declaredTables())), 'A build reads a table that no source declares.');
    }

    #[Test]
    public function every_declared_table_has_a_mark_trigger(): void
    {
        $connection = $this->connection();
        $triggered = $connection->fetchFirstColumn(
            "SELECT DISTINCT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND t.tgname LIKE 'workflow\\_mark\\_%'"
        );
        $declared = $this->declaredTables();

        self::assertNotSame([], $triggered);
        self::assertSame([], array_values(array_diff($declared, $triggered)), 'A declared table has no workflow_mark trigger.');
    }

    /** @return list<string> */
    private function declaredTables(): array
    {
        $providers = self::getContainer()->get(FactProviders::class);
        $builder = self::getContainer()->get(FactsBuilder::class);
        self::assertInstanceOf(FactProviders::class, $providers);
        self::assertInstanceOf(FactsBuilder::class, $builder);
        $tables = $builder->sourceTables();
        foreach ($providers->byClass as $provider) {
            $tables = [...$tables, ...$provider->sourceTables()];
        }
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    /** @return list<string> */
    private function tablesReadByBuilds(): array
    {
        self::bootKernel();
        $connection = $this->connection();
        $templates = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $templates);
        $rows = new RawRows($connection);
        MarkWorld::seed($rows, $connection, $templates->source('lifecycle'));
        $connection->executeStatement("UPDATE feature_flag SET value = 'true' WHERE name = ?", [InboxInstallFlags::FLAG_INBOX_ENABLED]);
        $rows->update('bridges', ['owner_id' => MarkWorld::id('OX'), 'id' => MarkWorld::id('BR1')], ['last_seen_at' => new \DateTimeImmutable(), 'projects' => [MarkWorld::id('PX')]]);
        $rows->insert('bridges', ['id' => Uuid::v4(), 'owner_id' => MarkWorld::id('OX'), 'login_id' => MarkWorld::id('LG1'), 'projects' => [MarkWorld::id('PX')], 'last_seen_at' => '2020-01-01 00:00:00']);
        $builder = self::getContainer()->get(FactsBuilder::class);
        $cards = self::getContainer()->get(CardDirectory::class);
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(FactsBuilder::class, $builder);
        self::assertInstanceOf(CardDirectory::class, $cards);
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $existing = $connection->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()');

        $statements = [];
        foreach (['C1', 'P', 'C2', 'N1', 'B', 'S', 'W'] as $name) {
            $snapshot = $cards->find(Uuid::fromString(MarkWorld::id($name))) ?? throw new \LogicException('The world holds the card '.$name.'.');
            $queries->reset();
            $facts = $builder->build($snapshot, new \DateTimeImmutable('2026-06-01 12:00:00'));
            foreach ($facts->provided as $class => $provided) {
                if ($provided instanceof Unreadable) {
                    self::fail(\sprintf('The facts %s of card %s are %s: %s', $class, $name, $provided->kind->name, $provided->cause?->getMessage() ?? 'no cause'));
                }
            }
            foreach ($queries->getData()['default'] ?? [] as $query) {
                $statements[] = $query['sql'];
            }
        }
        self::assertNotSame([], $statements, 'Guard: the builds ran no query.');

        $reads = [];
        foreach ($statements as $sql) {
            $ctes = preg_match_all('/(?:\bWITH(?:\s+RECURSIVE)?|,)\s+"?([a-z_][a-z0-9_]*)"?\s+AS\s+(?:NOT\s+)?(?:MATERIALIZED\s*)?\(/i', (string) $sql, $found) ? $found[1] : [];
            preg_match_all('/\b(?:FROM|JOIN)\s+"?([a-z_][a-z0-9_]*)"?(?!\s*\()/i', (string) $sql, $found);
            foreach ($found[1] as $table) {
                if (\in_array($table, $existing, true) && !\in_array($table, $ctes, true)) {
                    $reads[$table] = true;
                }
            }
        }
        $reads = array_keys($reads);
        sort($reads);

        return $reads;
    }

    private function connection(): Connection
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
