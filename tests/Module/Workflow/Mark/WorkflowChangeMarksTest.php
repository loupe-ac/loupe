<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

use App\Module\Workflow\Service\FactProviders;
use App\Module\Workflow\Service\FactsBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The triggers write the marks, so the test writes one row to each table that a fact reads, and reads back what got marked.
 *
 * @phpstan-import-type Case from MarkCases
 * @phpstan-import-type Marked from MarkCases
 */
final class WorkflowChangeMarksTest extends KernelTestCase
{
    /** @return iterable<string, array{string, Case}> */
    public static function tables(): iterable
    {
        foreach (MarkCases::all() as $table => $case) {
            yield $table => [$table, $case];
        }
    }

    /** @param Case $case */
    #[DataProvider('tables')]
    #[Test]
    public function a_write_marks_exactly_the_cards_and_projects_that_read_the_row(string $table, array $case): void
    {
        $world = $this->world();
        $rows = $world->rows;

        $world->clearMarks();
        $key = $rows->insert($table, $case['row']);
        $this->assertMarked($world, $case, 'insert', $table);

        $world->clearMarks();
        $rows->rewrite($table, $key, array_key_first($case['set']) ?? throw new \LogicException('A case changes one column.'));
        self::assertSame([[], []], $world->marks(), \sprintf('A write to %s that changes nothing marks nothing.', $table));

        $world->clearMarks();
        $rows->update($table, $key, $case['set']);
        $key = [...$key, ...array_intersect_key($case['set'], $key)];
        $this->assertMarked($world, $case, 'update', $table);

        foreach ($case['extras'] ?? [] as $label => [$write, $cards, $projects]) {
            $world->clearMarks();
            $write($rows);
            sort($cards);
            sort($projects);
            self::assertSame([$cards, $projects], $world->marks(), \sprintf('%s: %s.', $table, $label));
        }

        $world->clearMarks();
        $rows->delete($table, $key);
        $this->assertMarked($world, $case, 'delete', $table);
    }

    /** @param Case $case */
    #[DataProvider('checkedTables')]
    #[Test]
    public function a_check_does_not_mark_its_own_card_through_the_rows_it_writes(string $table, array $case): void
    {
        $world = $this->world();
        $rows = $world->rows;

        try {
            $this->declareCheck($world, 'C1');
            $world->clearMarks();
            $key = $rows->insert($table, $case['row']);
            $rows->update($table, $key, $case['set']);
            $rows->delete($table, $key);
            self::assertSame([[], []], $world->marks(), 'The check of C1 wrote its own rows.');

            $this->declareCheck($world, 'C2');
            $world->clearMarks();
            $key = $rows->insert($table, $case['row']);
            self::assertSame([['C1'], []], $world->marks(), 'The check of C2 wrote a row of C1.');
            $rows->update($table, $key, $case['set']);
            $rows->delete($table, $key);
        } finally {
            $world->db->executeStatement("SELECT set_config('app.workflow_checking_card', '', true)");
        }

        $world->clearMarks();
        $key = $rows->insert($table, $case['row']);
        self::assertSame([['C1'], []], $world->marks(), 'A write outside a check marks the card.');
        $rows->delete($table, $key);
    }

    /** @return iterable<string, array{string, Case}> */
    public static function checkedTables(): iterable
    {
        foreach (MarkCases::all() as $table => $case) {
            if ($case['skipsCheckedCard'] ?? false) {
                yield $table => [$table, $case];
            }
        }
    }

    #[Test]
    public function every_table_the_facts_read_has_a_case(): void
    {
        self::bootKernel();
        $providers = self::getContainer()->get(FactProviders::class);
        $builder = self::getContainer()->get(FactsBuilder::class);
        self::assertInstanceOf(FactProviders::class, $providers);
        self::assertInstanceOf(FactsBuilder::class, $builder);
        $declared = $builder->sourceTables();
        foreach ($providers->byClass as $provider) {
            $declared = [...$declared, ...$provider->sourceTables()];
        }
        $declared = array_values(array_unique($declared));
        sort($declared);
        $covered = array_keys(MarkCases::all());
        sort($covered);

        self::assertNotSame([], $declared);
        self::assertSame([], array_values(array_diff($declared, $covered)), 'A declared table has no mark case.');
        self::assertSame([], array_values(array_diff($covered, $declared)), 'A mark case names a table that no provider declares.');
    }

    /**
     * @param Case                       $case
     * @param 'insert'|'update'|'delete' $write
     */
    private function assertMarked(MarkWorld $world, array $case, string $write, string $table): void
    {
        [$cards, $projects] = $case['expect'][$write] ?? [$case['cards'], $case['projects']];
        sort($cards);
        sort($projects);

        self::assertSame([$cards, $projects], $world->marks(), \sprintf('The %s of a row of %s marks these cards and projects.', $write, $table));
    }

    private function declareCheck(MarkWorld $world, string $card): void
    {
        $world->db->executeStatement("SELECT set_config('app.workflow_checking_card', ?, true)", [MarkWorld::id($card)]);
    }

    private function world(): MarkWorld
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return MarkWorld::seed(new RawRows($connection), $connection);
    }
}
