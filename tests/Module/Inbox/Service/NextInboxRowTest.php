<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Service;

use App\Module\Inbox\Service\NextInboxRow;
use App\Module\Inbox\View\InboxView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextInboxRowTest extends TestCase
{
    /**
     * @param list<string> $before
     * @param list<string> $after
     */
    #[DataProvider('picks')]
    public function test_it_picks_the_row_after_the_acted_one(array $before, array $after, string $acted, ?string $expected): void
    {
        self::assertSame($expected, NextInboxRow::after($before, $after, $acted));
    }

    /** @return iterable<string, array{list<string>, list<string>, string, ?string}> */
    public static function picks(): iterable
    {
        yield 'the row after' => [['a', 'b', 'c'], ['a', 'c'], 'b', 'c'];
        yield 'the last row falls back to the one before' => [['a', 'b', 'c'], ['a', 'b'], 'c', 'b'];
        yield 'a row that left the list is skipped' => [['a', 'b', 'c', 'd'], ['a', 'd'], 'b', 'd'];
        yield 'nothing after, the nearest before' => [['a', 'b', 'c', 'd'], ['a', 'b'], 'c', 'b'];
        yield 'a new row has no place' => [['a', 'b'], ['a', 'b', 'z'], 'b', 'a'];
        yield 'no row left' => [['a'], [], 'a', null];
        yield 'an acted row the list never held' => [['a', 'b'], ['a', 'b'], 'x', null];
    }

    public function test_it_selects_an_ask_or_a_loose_item(): void
    {
        $ask = '01a131f4-3abc-7cb1-b0c7-c0f5fead0f90';

        self::assertSame($ask, NextInboxRow::select(new InboxView(), $ask)->askId?->toRfc4122());
        self::assertSame(7, NextInboxRow::select(new InboxView(), 'item-7')->itemNumber);
    }
}
