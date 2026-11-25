<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestCommand;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Command\WorkRequestAlreadyLive;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Workflow\Template\AppRules;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gives each live run that holds a card its work request about the run. While a deploy runs, the previous image can
 * start or change a run, and open a card request for it. The engine cancels that request, and no listener hears of
 * the cancel, so without this the run would wait for good. Temporary: it goes with the card of a run.
 * Answers how many runs it changed.
 */
final readonly class AdoptLegacyDiscoveryRunsHandler
{
    private const array RULE_IDS = [FailDiscoveryRunHandler::RULE_ID, FailDiscoveryRunHandler::REVISE_RULE_ID];

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private ProjectRepository $projects,
        private WorkRequestRepository $workRequests,
        private OpenWorkRequestHandler $openWorkRequest,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private OpenDiscoveryRevisionHandler $openDiscoveryRevision,
        private FailDiscoveryRunHandler $failDiscoveryRun,
        private AppRules $appRules,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AdoptLegacyDiscoveryRunsCommand $command): int
    {
        $changed = 0;
        foreach ($this->discoveryRuns->findLiveWithCard() as $run) {
            try {
                $changed += (int) $this->adopt($run);
            } catch (\Throwable $e) {
                // One run that fails must not hold back the others. A closed entity manager serves no other run.
                if (!$this->em->isOpen()) {
                    throw $e;
                }
                $this->logger->error('readiness.legacy_run_adoption_failed', ['discoveryRunId' => (string) $run->id, 'exception' => $e]);
            }
        }

        return $changed;
    }

    private function adopt(DiscoveryRun $run): bool
    {
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        $step = $this->em->wrapInTransaction(fn (): LegacyDiscoveryRunStep => $this->step($run));

        foreach ($step->copies as $copy) {
            ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($copy, WorkRequestState::Cancelled));
        }
        if (FailDiscoveryRunHandler::REVISE_RULE_ID === $step->openRuleId) {
            // It keeps the request that a queued message opened first, and that message keeps this one.
            ($this->openDiscoveryRevision)(new OpenDiscoveryRevisionCommand($runId, null));
        } elseif (FailDiscoveryRunHandler::RULE_ID === $step->openRuleId) {
            $this->openFirstRequest($run);
        }

        return $step->moved || [] !== $step->copies || null !== $step->openRuleId;
    }

    /** One step for the run. Call it inside a transaction. */
    private function step(DiscoveryRun $run): LegacyDiscoveryRunStep
    {
        $this->projects->lockForWrite($run->project);
        $wanted = match ($this->discoveryRuns->freshStateOf($run)['state']) {
            DiscoveryRunState::Requested => FailDiscoveryRunHandler::RULE_ID,
            DiscoveryRunState::Revising => FailDiscoveryRunHandler::REVISE_RULE_ID,
            default => null,
        };
        $card = $run->card;
        if (null === $wanted || null === $card) {
            return new LegacyDiscoveryRunStep();
        }

        $subject = new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.'));
        $onCard = array_values(array_filter(
            $this->workRequests->findLiveOfSubject(WorkSubject::card($card->id ?? throw new \LogicException('A stored card has an id.'))),
            static fn (WorkRequest $request): bool => $request->project === $run->project && \in_array($request->ruleId, self::RULE_IDS, true),
        ));
        $matches = static fn (WorkRequest $request): bool => $wanted === $request->ruleId;

        // A request of the other rule does not count: a first discovery request can outlive the report, and asks for no revision.
        if (array_any($this->workRequests->findLiveOfSubject($subject), $matches)) {
            return new LegacyDiscoveryRunStep(copies: array_map(
                static fn (WorkRequest $request): Uuid => $request->id ?? throw new \LogicException('A persisted work request has an id.'),
                $onCard,
            ));
        }
        if (!array_any($onCard, $matches)) {
            return new LegacyDiscoveryRunStep(openRuleId: $wanted);
        }

        $moved = false;
        foreach ($onCard as $request) {
            if ($this->workRequests->moveCardRequestToSubject($request, $subject, StartDiscoveryHandler::CAPABILITY, $this->appRules->prompt($request->ruleId), $this->clock->now())) {
                $moved = true;
                if ($matches($request)) {
                    $run->workRequestId = $request->id;
                }
            }
        }
        $this->em->flush();

        return new LegacyDiscoveryRunStep(moved: $moved);
    }

    /** Opens the request outside any transaction, as a start does, and stores its id. */
    private function openFirstRequest(DiscoveryRun $run): void
    {
        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $run->project,
                subject: new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.')),
                cardNumber: null,
                kind: StartDiscoveryHandler::WORK_KIND,
                capability: StartDiscoveryHandler::CAPABILITY,
                ruleId: StartDiscoveryHandler::WORK_KIND,
                context: new WorkRequestContext(),
                prompt: $this->appRules->prompt(StartDiscoveryHandler::WORK_KIND),
                failWhenNoTaker: true,
            ));
        } catch (WorkRequestAlreadyLive) {
            return;
        } catch (DomainErrors $e) {
            $this->logger->error('readiness.legacy_run_request_refused', ['discoveryRunId' => (string) $run->id, 'errors' => $e->errors]);
            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, StartDiscoveryHandler::REQUEST_REFUSED, FailDiscoveryRunHandler::RULE_ID));

            return;
        }

        $run->workRequestId = $request->id;
        $this->em->flush();
    }
}
