<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * What a live read of page one changes on a screen that shows some rows of it.
 * It never reorders a row the screen holds.
 *
 * @template T of object
 */
final readonly class RowChanges
{
    /**
     * @param list<T>            $kept       the rows of the page that the screen shows
     * @param list<string>       $goneIds    the shown rows that the page no longer holds
     * @param list<RowInsert<T>> $inserts    the rows the screen lacks, last row first, so the row that follows each one is already in place
     * @param bool               $replaceAll whether the screen was empty and the page is not, or the reverse
     */
    private function __construct(
        public array $kept,
        public array $goneIds,
        public array $inserts,
        public bool $replaceAll,
    ) {
    }

    /**
     * @template R of object
     *
     * @param list<R>             $pageItems the rows of the page, in page order
     * @param \Closure(R): string $idOf
     * @param list<string>        $shownIds  the ids of the rows on screen
     *
     * @return self<R>
     */
    public static function between(array $pageItems, \Closure $idOf, array $shownIds): self
    {
        $shown = array_flip($shownIds);
        $pageIds = array_map($idOf, $pageItems);

        $kept = [];
        $inserts = [];
        foreach ($pageItems as $index => $item) {
            if (isset($shown[$pageIds[$index]])) {
                $kept[] = $item;
            } else {
                $inserts[] = new RowInsert($item, $pageIds[$index + 1] ?? null);
            }
        }

        return new self(
            $kept,
            array_values(array_diff($shownIds, $pageIds)),
            array_reverse($inserts),
            [] === $shownIds xor [] === $pageItems,
        );
    }
}
