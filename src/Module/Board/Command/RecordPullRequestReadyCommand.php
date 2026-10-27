<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\Entity\ForgePullRequest;

/** A read found a pull request that turned ready to merge. */
final readonly class RecordPullRequestReadyCommand
{
    public function __construct(
        public ForgePullRequest $pullRequest,
        public \DateTimeImmutable $readyAt,
    ) {
    }
}
