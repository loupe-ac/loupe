<?php

declare(strict_types=1);

namespace App\Search;

final readonly class SearchResults
{
    private const array KIND_ORDER = ['card', 'document', 'page'];

    /** @param list<SearchResult> $items */
    public function __construct(
        public array $items = [],
        public bool $hasMore = false,
    ) {
    }

    /**
     * Takes one row from each kind in turn until $limit rows are picked, so a long group cannot hide the others.
     *
     * @return array<string, list<SearchResult>> The picked rows by kind, in the order of the dropdown
     */
    public function dropdown(int $limit = 10): array
    {
        $queues = array_fill_keys(self::KIND_ORDER, []);
        foreach ($this->items as $item) {
            $queues[$item->kind][] = $item;
        }
        $picked = array_fill_keys(self::KIND_ORDER, []);
        $count = 0;
        for ($round = 0; $count < $limit; ++$round) {
            $took = false;
            foreach ($queues as $kind => $queue) {
                if ($count < $limit && isset($queue[$round])) {
                    $picked[$kind][] = $queue[$round];
                    ++$count;
                    $took = true;
                }
            }
            if (!$took) {
                break;
            }
        }

        return array_filter($picked);
    }
}
