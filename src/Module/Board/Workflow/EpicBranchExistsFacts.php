<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\TimedFacts;

final readonly class EpicBranchExistsFacts implements TimedFacts
{
    /** @param ?\DateTimeImmutable $changeAt when the first live bridge with a repository goes quiet, or null when none is live */
    public function __construct(
        public bool $exists,
        public ?\DateTimeImmutable $changeAt = null,
    ) {
    }

    #[\Override]
    public function changesAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->changeAt;
    }
}
