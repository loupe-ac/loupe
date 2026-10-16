<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Workflow;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\EpicBranchMerger;

/** Records each merge into an epic branch on `github`, and throws the failure it holds. */
final class FakeEpicBranchMerger implements EpicBranchMerger
{
    /** @var list<array{repository: string, epicBranch: string, baseBranch: string}> */
    public array $calls = [];

    public ?\Throwable $failure = null;

    #[\Override]
    public function supports(string $forge): bool
    {
        return 'github' === $forge;
    }

    #[\Override]
    public function merge(ForgePullRequest $reference, string $epicBranch, string $baseBranch): void
    {
        $this->calls[] = ['repository' => $reference->repository, 'epicBranch' => $epicBranch, 'baseBranch' => $baseBranch];
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
