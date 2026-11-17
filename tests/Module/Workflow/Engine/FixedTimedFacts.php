<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Contract\TimedFacts;

/** Facts that name their next change time. */
final readonly class FixedTimedFacts implements TimedFacts
{
    public function __construct(
        private \DateTimeImmutable $at,
    ) {
    }

    #[\Override]
    public function changesAt(): \DateTimeImmutable
    {
        return $this->at;
    }
}
