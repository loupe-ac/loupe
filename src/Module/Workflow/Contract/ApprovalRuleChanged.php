<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the approval rule of a project changes, so each open approval is judged again. */
final readonly class ApprovalRuleChanged
{
    public function __construct(
        public Uuid $projectId,
    ) {
    }
}
