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
use Psr\Log\LoggerInterface;

/**
 * Opens the work request that revises the report of a run, outside any transaction, and stores its id.
 * The message can arrive again, so a request that an earlier delivery opened is kept.
 */
final readonly class OpenDiscoveryRevisionHandler
{
    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private ProjectRepository $projects,
        private OpenWorkRequestHandler $openWorkRequest,
        private WithdrawWorkRequestHandler $withdrawWorkRequest,
        private WorkRequestRepository $workRequests,
        private FailDiscoveryRunHandler $failDiscoveryRun,
        private AppRules $appRules,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(OpenDiscoveryRevisionCommand $command): void
    {
        $run = $this->discoveryRuns->find($command->runId);
        if (null === $run || DiscoveryRunState::Revising !== $this->discoveryRuns->freshStateOf($run)['state']) {
            return;
        }

        try {
            $request = $this->openOrAdopt($run, $command);
        } catch (DomainErrors $e) {
            $this->logger->error('readiness.revision_request_refused', ['discoveryRunId' => (string) $run->id, 'errors' => $e->errors]);
            ($this->failDiscoveryRun)(new FailDiscoveryRunCommand($run, StartDiscoveryHandler::REQUEST_REFUSED, FailDiscoveryRunHandler::REVISE_RULE_ID));

            return;
        }

        // The project lock orders this against an approval: it reads the new id, or the read below sees the run it ended.
        $state = $this->em->wrapInTransaction(function () use ($run, $request): DiscoveryRunState {
            $this->projects->lockForWrite($run->project);
            $run->workRequestId = $request->id;
            $this->em->flush();

            return $this->discoveryRuns->freshStateOf($run)['state'];
        });
        if (DiscoveryRunState::Done === $state || DiscoveryRunState::Failed === $state) {
            ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($request->id ?? throw new \LogicException('A persisted work request has an id.'), WorkRequestState::Cancelled));
        }
    }

    private function openOrAdopt(DiscoveryRun $run, OpenDiscoveryRevisionCommand $command): WorkRequest
    {
        $subject = new WorkSubject(DiscoveryRun::SUBJECT_TYPE, $command->runId);
        $open = new OpenWorkRequestCommand(
            project: $run->project,
            subject: $subject,
            cardNumber: null,
            kind: FailDiscoveryRunHandler::REVISE_RULE_ID,
            capability: StartDiscoveryHandler::CAPABILITY,
            ruleId: FailDiscoveryRunHandler::REVISE_RULE_ID,
            context: new WorkRequestContext(),
            prompt: $this->appRules->prompt(FailDiscoveryRunHandler::REVISE_RULE_ID),
            failWhenNoTaker: true,
        );

        try {
            return ($this->openWorkRequest)($open);
        } catch (WorkRequestAlreadyLive) {
            $live = array_find(
                $this->workRequests->findLiveOfSubject($subject),
                static fn (WorkRequest $request): bool => FailDiscoveryRunHandler::REVISE_RULE_ID === $request->kind,
            );
            if (null !== $live) {
                $liveId = $live->id ?? throw new \LogicException('A persisted work request has an id.');
                if (true !== $command->previousWorkRequestId?->equals($liveId)) {
                    return $live;
                }
                // The worker of the revision before this one reported, and its request did not settle yet.
                ($this->withdrawWorkRequest)(new WithdrawWorkRequestCommand($liveId, WorkRequestState::Cancelled));
            }

            return ($this->openWorkRequest)($open);
        }
    }
}
