<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

/**
 * What one run spent in one time bucket. The figures with subagents are null on a row that no recompute has
 * touched since the columns were added.
 */
final readonly class WorkerRunBucketView
{
    public function __construct(
        public string $bucket,
        public int $mainMs,
        public ?int $withSubagentsMs,
        public ?int $calls,
    ) {
    }

    public function mainDuration(): string
    {
        return WorkerRunListItem::formatDuration(intdiv($this->mainMs, 1000));
    }

    public function withSubagentsDuration(): ?string
    {
        return null === $this->withSubagentsMs ? null : WorkerRunListItem::formatDuration(intdiv($this->withSubagentsMs, 1000));
    }
}
