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
use App\Module\Readiness\Service\DiscoveryWorkRequestStore;
use App\Module\Workflow\Template\AppRules;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives each live run that holds a card its work request about the run, where the upgrade or the previous image left
 * a card request or none. No listener hears the engine cancel a card request, so without this the run would wait
 * for good. A claimed card request moves, and an open one is replaced. Temporary: it goes with the card of a run.
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
        private DiscoveryWorkRequestStore $storeWorkRequest,
        private AppRules $appRules,
        private EntityManagerInterface $em,
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
        // First, so the step below reads what is left. A request that a bridge claimed in between stays, and moves.
        $replaced = 0;
        foreach ($this->cardRequests($run) as $request) {
            $replaced += (int) ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A persisted work request has an id.'), WorkRequestState::Cancelled, onlyIfOpen: true));
        }
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

        return $replaced > 0 || $step->moved || [] !== $step->copies || null !== $step->openRuleId;
    }

    /** @return list<WorkRequest> the live requests of the two discovery rules on the card of the run */
    private function cardRequests(DiscoveryRun $run): array
    {
        $cardId = $run->card?->id;
        if (null === $cardId) {
            return [];
        }

        return array_values(array_filter(
            $this->workRequests->findLiveOfSubject(WorkSubject::card($cardId)),
            static fn (WorkRequest $request): bool => $request->project === $run->project && \in_array($request->ruleId, self::RULE_IDS, true),
        ));
    }

    /** Moves the claimed requests of the card, and says what else the run needs. Call it inside a transaction. */
    private function step(DiscoveryRun $run): LegacyDiscoveryRunStep
    {
        $this->projects->lockForWrite($run->project);
        $fresh = $this->discoveryRuns->freshStateOf($run);
        $wanted = match ($fresh['state']) {
            DiscoveryRunState::Requested => FailDiscoveryRunHandler::RULE_ID,
            DiscoveryRunState::Revising => FailDiscoveryRunHandler::REVISE_RULE_ID,
            default => null,
        };
        if (null === $wanted) {
            return new LegacyDiscoveryRunStep();
        }

        $subject = new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.'));
        $moved = false;
        $copies = [];
        $waiting = false;
        foreach ($this->cardRequests($run) as $request) {
            $requestId = $request->id ?? throw new \LogicException('A persisted work request has an id.');
            if ($this->workRequests->moveCardRequestToSubject($request, $subject, StartDiscoveryHandler::CAPABILITY, $this->appRules->prompt($request->ruleId))) {
                $moved = true;
                if ($wanted === $request->ruleId) {
                    $run->workRequestId = $requestId;
                }
            } elseif (null !== $this->workRequests->liveRuleId($subject, $request->kind)) {
                // The run has this work already, so the previous image opened a copy.
                $copies[] = $requestId;
            } else {
                // A lapsed claim opened it again after the cancel above, so the next pass replaces it.
                $waiting = $waiting || $wanted === $request->ruleId;
            }
        }
        $this->em->flush();

        // A request of the other rule asks for other work, and a report answered the work of an answered one.
        $served = array_any(
            $this->workRequests->findLiveOfSubject($subject),
            static fn (WorkRequest $request): bool => $wanted === $request->ruleId && !\in_array((string) $request->id, $fresh['answeredWorkRequestIds'], true),
        );

        return new LegacyDiscoveryRunStep(moved: $moved, copies: $copies, openRuleId: $served || $waiting ? null : $wanted);
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

        // The run can change while the request opens.
        $this->storeWorkRequest->store($run, $request, DiscoveryRunState::Requested);
    }
}
