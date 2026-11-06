<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The columns that a rule compares to the microsecond. A whole-second column would turn a later write into a tie. */
final class MicrosecondColumnsMigrationTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function columns(): iterable
    {
        yield 'the time of a review' => ['reviews', 'submitted_at'];
        yield 'the time of a card value write' => ['board_card_values', 'updated_at'];
        yield 'the time of a card event' => ['board_card_events', 'occurred_at'];
        yield 'the time of a document publication' => ['document_versions', 'published_at'];
    }

    #[DataProvider('columns')]
    public function test_the_column_keeps_six_digits_of_the_second(string $table, string $column): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $precision = $connection->fetchOne(
            'SELECT datetime_precision FROM information_schema.columns WHERE table_name = :table AND column_name = :column',
            ['table' => $table, 'column' => $column],
        );

        self::assertSame(6, $precision);
    }
}
