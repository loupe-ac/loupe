<?php

declare(strict_types=1);

namespace App\Tests\Utils;

use App\Utils\RowChanges;
use App\Utils\RowInsert;
use PHPUnit\Framework\TestCase;

final class RowChangesTest extends TestCase
{
    public function test_a_shown_row_is_kept_a_missing_row_is_inserted_and_a_left_row_is_gone(): void
    {
        $changes = $this->between(['a', 'b', 'c'], ['b', 'x']);

        self::assertSame(['b'], $this->ids($changes->kept));
        self::assertSame(['x'], $changes->goneIds);
        self::assertSame([['c', null], ['a', 'b']], $this->inserts($changes));
        self::assertFalse($changes->replaceAll);
    }

    public function test_inserts_run_from_the_last_row_so_a_follower_is_always_in_place(): void
    {
        $changes = $this->between(['a', 'b', 'c', 'd'], ['a']);

        self::assertSame([['d', null], ['c', 'd'], ['b', 'c']], $this->inserts($changes));
    }

    public function test_an_empty_screen_or_an_empty_page_replaces_everything(): void
    {
        self::assertTrue($this->between(['a'], [])->replaceAll);
        self::assertTrue($this->between([], ['a'])->replaceAll);
        self::assertFalse($this->between([], [])->replaceAll);
    }

    public function test_an_unchanged_page_changes_nothing(): void
    {
        $changes = $this->between(['a', 'b'], ['a', 'b']);

        self::assertSame(['a', 'b'], $this->ids($changes->kept));
        self::assertSame([], $changes->goneIds);
        self::assertSame([], $changes->inserts);
    }

    /**
     * @param list<string> $page
     * @param list<string> $shown
     *
     * @return RowChanges<RowStub>
     */
    private function between(array $page, array $shown): RowChanges
    {
        return RowChanges::between(
            array_map(static fn (string $id): RowStub => new RowStub($id), $page),
            static fn (RowStub $row): string => $row->id,
            $shown,
        );
    }

    /**
     * @param list<RowStub> $rows
     *
     * @return list<string>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (RowStub $row): string => $row->id, $rows);
    }

    /**
     * @param RowChanges<RowStub> $changes
     *
     * @return list<array{string, ?string}>
     */
    private function inserts(RowChanges $changes): array
    {
        return array_map(static fn (RowInsert $insert): array => [$insert->item->id, $insert->beforeId], $changes->inserts);
    }
}
