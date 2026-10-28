<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The work that a rule requests up to a limit, and the pull request it last acted on for one card. */
final readonly class RuleWorkLimit
{
    public function __construct(
        public string $workKind,
        public ?string $reason,
        public int $limit,
        public ?Uuid $pullRequestId,
    ) {
    }
}
