<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Command\ProposeMergeApprovalCommand;
use App\Module\Inbox\Command\ProposeMergeApprovalHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OpenMergeApprovalPullRequestHandler
{
    public function __construct(
        private ProposeMergeApprovalHandler $propose,
    ) {
    }

    public function __invoke(OpenMergeApprovalPullRequest $message): void
    {
        ($this->propose)(new ProposeMergeApprovalCommand($message->itemId));
    }
}
