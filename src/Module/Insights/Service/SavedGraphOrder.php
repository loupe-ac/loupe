<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;

/**
 * Moves a graph among the graphs on show. The graphs on show reuse their own positions in the new order,
 * so a removed graph keeps its place for Undo. Call it inside a transaction, under the lock of SavedGraphLocker.
 */
final readonly class SavedGraphOrder
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
    ) {
    }

    /**
     * @param int $steps places to move. Negative moves up. A move past either end stops at the end.
     *
     * @return bool false when the graph did not move
     */
    public function moveBy(InsightsSavedGraph $graph, int $steps): bool
    {
        return $this->place($graph, static fn (int $from): int => $from + $steps);
    }

    /**
     * @param int $place the place to take, counted from 0. A place past the last puts the graph last.
     *
     * @return bool false when the graph did not move
     */
    public function moveTo(InsightsSavedGraph $graph, int $place): bool
    {
        return $this->place($graph, static fn (): int => $place);
    }

    /** @param \Closure(int): int $target */
    private function place(InsightsSavedGraph $graph, \Closure $target): bool
    {
        $ordered = $this->insightsSavedGraphs->findActive($graph->project);
        $from = array_find_key($ordered, static fn (InsightsSavedGraph $other): bool => $other->id?->equals($graph->id) ?? false);
        if (null === $from) {
            return false;
        }
        $to = max(0, min(\count($ordered) - 1, $target($from)));
        if ($to === $from) {
            return false;
        }

        $positions = array_map(static fn (InsightsSavedGraph $other): int => $other->position, $ordered);
        [$moving] = array_splice($ordered, $from, 1);
        array_splice($ordered, $to, 0, [$moving]);
        foreach ($ordered as $index => $other) {
            $other->position = $positions[$index];
        }

        return true;
    }
}
