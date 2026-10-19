<?php

declare(strict_types=1);

namespace App\Module\Forge\EventListener;

use App\Module\Forge\Messenger\RefreshPullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Workflow\Contract\ApprovalRuleChanged;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/** A stored coverage judgement follows the rule it was made under, so a rule change judges each open approval again. */
#[AsEventListener]
final readonly class RejudgeApprovalCoverageOnApprovalRuleChanged
{
    public function __construct(
        private ForgePullRequestRepository $forgePullRequests,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ApprovalRuleChanged $event): void
    {
        $now = $this->clock->now();
        foreach ($this->forgePullRequests->resetApprovalCoverage($event->projectId) as $id) {
            $this->bus->dispatch(new RefreshPullRequestState((string) $id, $now));
        }
    }
}
