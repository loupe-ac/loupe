<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

/** The manual exclusion of one card, as the report shows it. */
final readonly class ExperimentExclusionView
{
    public function __construct(
        public string $reason,
        /** Null when the author's account is gone. */
        public ?string $authorName,
        public \DateTimeImmutable $excludedAt,
    ) {
    }
}
