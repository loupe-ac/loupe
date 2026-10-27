<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PullRequestApprovers
{
    /** @param iterable<PullRequestApprover> $approvers */
    public function __construct(
        #[AutowireIterator('app.pull_request_approver')]
        private iterable $approvers,
    ) {
    }

    public function for(string $forge): ?PullRequestApprover
    {
        foreach ($this->approvers as $approver) {
            if ($approver->supports($forge)) {
                return $approver;
            }
        }

        return null;
    }
}
