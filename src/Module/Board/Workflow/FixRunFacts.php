<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\TimedFacts;

/** The open or recently ended fix runs of one card that no pull request comment announces yet. */
final readonly class FixRunFacts implements TimedFacts
{
    /**
     * @param list<string>        $uncommentedRunIds sorted
     * @param ?\DateTimeImmutable $changeAt          when the first closed run leaves the comment window, or null when none does
     */
    public function __construct(
        public array $uncommentedRunIds,
        public ?\DateTimeImmutable $changeAt = null,
    ) {
    }

    #[\Override]
    public function changesAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->changeAt;
    }
}
