<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

final readonly class ProposeMergeApprovalCommand
{
    public function __construct(
        public string $itemId,
    ) {
    }
}
