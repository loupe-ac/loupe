<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Workflow\Contract\PersonWork;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Expires the open requests about a subject other than a card that no bridge
 * took in time, so the module of the subject hears of it. The workflow engine
 * expires the work of a card by its own rules, except the work that a person
 * asked for. An open session request of a person expires here after a short
 * wait, and other work of a person expires after the same timeout as the work
 * of a subject, with no pause of the card. A request that waits through a
 * work hold of its project keeps its time until the hold ends. Answers how many expired.
 */
final readonly class ExpireSubjectWorkRequestsHandler
{
    /** A person who clicks Start a session waits at the screen, so a bridge that goes offline must not open a terminal hours later. */
    public const int SESSION_TIMEOUT_MINUTES = 2;

    public function __construct(
        private WorkRequestRepository $workRequests,
        private WithdrawWorkRequestHandler $withdraw,
        private ClockInterface $clock,

        #[Autowire(param: 'app.bridge.subject_work_timeout_minutes')]
        private int $timeoutMinutes,
    ) {
    }

    public function __invoke(ExpireSubjectWorkRequestsCommand $command): int
    {
        $deadline = $this->clock->now()->modify(\sprintf('-%d minutes', $this->timeoutMinutes));
        $sessionDeadline = $this->clock->now()->modify(\sprintf('-%d minutes', self::SESSION_TIMEOUT_MINUTES));
        $expired = 0;
        foreach ([
            [$deadline, $this->workRequests->findOpenOfOtherSubjectsBefore($deadline)],
            [$sessionDeadline, $this->workRequests->findOpenOfRulePrefixAndKindBefore(PersonWork::RULE_PREFIX, WorkRequest::SESSION_KIND, $sessionDeadline)],
        ] as [$requestDeadline, $requests]) {
            foreach ($requests as $request) {
                $id = $request->id ?? throw new \LogicException('A stored request has an id.');
                $expired += (int) ($this->withdraw)(new WithdrawWorkRequestCommand($id, WorkRequestState::Expired, onlyIfOpen: true, deadline: $requestDeadline));
            }
        }

        foreach ($this->workRequests->findOpenOfCardsByRulePrefix(PersonWork::RULE_PREFIX) as $request) {
            $id = $request->id ?? throw new \LogicException('A stored request has an id.');
            if (($request->reopenedAt ?? $request->createdAt) <= $deadline) {
                $expired += (int) ($this->withdraw)(new WithdrawWorkRequestCommand($id, WorkRequestState::Expired, onlyIfOpen: true, deadline: $deadline));
            }
        }

        return $expired;
    }
}
