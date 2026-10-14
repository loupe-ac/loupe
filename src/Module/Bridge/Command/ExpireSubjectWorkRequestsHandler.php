<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\PersonWork;
use App\Module\Workflow\Contract\WorkTimeouts;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Expires the open requests about a subject other than a card that no bridge
 * took in time, so the module of the subject hears of it. The workflow engine
 * expires the work of a card by its own rules, except the work that a person
 * asked for. That work expires after the work timeout of the project template,
 * and the card gets no pause. Answers how many expired.
 */
final readonly class ExpireSubjectWorkRequestsHandler
{
    public function __construct(
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdraw,
        private ClockInterface $clock,
        private WorkTimeouts $workTimeouts,

        #[Autowire(param: 'app.bridge.subject_work_timeout_minutes')]
        private int $timeoutMinutes,
    ) {
    }

    public function __invoke(ExpireSubjectWorkRequestsCommand $command): int
    {
        $deadline = $this->clock->now()->modify(\sprintf('-%d minutes', $this->timeoutMinutes));
        $expired = 0;
        foreach ($this->workRequests->findOpenOfOtherSubjectsBefore($deadline) as $request) {
            $id = $request->id ?? throw new \LogicException('A stored request has an id.');
            $expired += (int) ($this->withdraw)(new WithdrawWorkRequestCommand($id, WorkRequestState::Expired, onlyIfOpen: true));
        }

        $now = $this->clock->now();
        $timeouts = [];
        foreach ($this->workRequests->findOpenOfCardsByRulePrefix(PersonWork::RULE_PREFIX) as $request) {
            $id = $request->id ?? throw new \LogicException('A stored request has an id.');
            $projectId = $request->project->id ?? throw new \LogicException('A stored project has an id.');
            $minutes = $timeouts[$projectId->toRfc4122()] ??= $this->workTimeouts->minutes($projectId);
            if (($request->reopenedAt ?? $request->createdAt)->modify(\sprintf('+%d minutes', $minutes)) <= $now) {
                $expired += (int) ($this->withdraw)(new WithdrawWorkRequestCommand($id, WorkRequestState::Expired, onlyIfOpen: true));
            }
        }

        return $expired;
    }
}
