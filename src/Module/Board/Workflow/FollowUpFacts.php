<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

final readonly class FollowUpFacts
{
    public function __construct(
        public bool $followsAny,
        public bool $followsOnlyFinished,
    ) {
    }
}
