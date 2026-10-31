<?php

declare(strict_types=1);

namespace App\Module\Insights\View;

use App\Module\Insights\Entity\InsightsSavedGraph;

/**
 * One tile of the Dashboard. A broken tile names what is gone and draws no chart. A waiting tile has no kept
 * result yet, so the page loads it in a lazy frame. A ready tile carries the result to draw.
 */
final readonly class DashboardTileView
{
    public function __construct(
        public InsightsSavedGraph $graph,
        public ?BrokenGraph $broken,
        public ?DashboardGraphResult $result,
        /** False for the first tile, which cannot move up. */
        public bool $canMoveUp,
        /** False for the last tile, which cannot move down. */
        public bool $canMoveDown,
    ) {
    }

    public function isBroken(): bool
    {
        return null !== $this->broken;
    }

    public function isReady(): bool
    {
        return null !== $this->result;
    }

    public function isWaiting(): bool
    {
        return null === $this->broken && null === $this->result;
    }

    /** The id of the Turbo frame that holds the tile. The lazy frame and the frame the single tile route answers share it. */
    public function frameId(): string
    {
        return 'dashboard-graph-'.$this->graph->id;
    }
}
