<?php

declare(strict_types=1);

namespace App\Module\Readiness\WorkSubject;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\FormerWorkSubjectInterface;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlerInterface;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Command\FailDiscoveryRunCommand;
use App\Module\Readiness\Command\FailDiscoveryRunHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Fails a discovery run whose work ended before a worker could run it. It finds the run by the subject id
 * of the request, because a request can expire before the run stores the request id.
 */
final readonly class DiscoveryWorkSubjectHandler implements FormerWorkSubjectInterface, WorkSubjectHandlerInterface
{
    public const string REFUSED = 'refused';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private FailDiscoveryRunHandler $failDiscoveryRun,
    ) {
    }

    #[\Override]
    public static function subjectType(): string
    {
        return DiscoveryRun::SUBJECT_TYPE;
    }

    #[\Override]
    public function subjectExists(Project $project, Uuid $subjectId): bool
    {
        return null !== $this->discoveryRuns->findOneBy(['id' => $subjectId, 'project' => $project]);
    }

    /** A run that started when a run had a card moved its live requests from that card to itself. */
    #[\Override]
    public function isFormerSubjectOf(WorkRequest $request, WorkSubject $subject): bool
    {
        if (DiscoveryRun::SUBJECT_TYPE !== $request->subjectType || !$subject->isCard()) {
            return false;
        }

        return true === $this->discoveryRuns->find($request->subjectId)?->card?->id?->equals($subject->id);
    }

    /** A done request says nothing: the report, or the end of the worker, moves the run. */
    #[\Override]
    public function onSettled(WorkRequest $request): void
    {
        if (WorkRequestState::Refused === $request->state) {
            $this->fail($request, $request->reason ?? self::REFUSED);
        }
    }

    #[\Override]
    public function onExpired(WorkRequest $request): void
    {
        $this->fail($request, $request->reason ?? WorkRequest::REASON_NO_TAKER);
    }

    private function fail(WorkRequest $request, string $reason): void
    {
        $run = $this->discoveryRuns->find($request->subjectId);
        if (null !== $run) {
            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, $reason, $request->ruleId, $request->id));
        }
    }
}
