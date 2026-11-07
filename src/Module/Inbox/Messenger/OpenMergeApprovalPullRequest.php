<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

/** The owner answered the merge question of a repository. The item id finds the question. */
final readonly class OpenMergeApprovalPullRequest
{
    public function __construct(
        public string $itemId,
    ) {
    }
}
