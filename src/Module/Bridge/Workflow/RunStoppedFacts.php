<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\View\CardRunWarning;

/** The warning of the latest run of the card, and the time of the newest move of the card. */
final readonly class RunStoppedFacts
{
    public function __construct(
        public ?CardRunWarning $warning,
        public ?\DateTimeImmutable $movedAt,
    ) {
    }

    /** A card that moved to another column since its run ended no longer waits on that run. */
    public function stopped(): ?CardRunWarning
    {
        $endedAt = $this->warning?->endedAt;

        return null !== $endedAt && null !== $this->movedAt && $this->movedAt >= $endedAt ? null : $this->warning;
    }
}
