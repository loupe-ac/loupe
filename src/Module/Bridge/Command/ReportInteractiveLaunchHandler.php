<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\Service\ReportedWorkRequest;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\InitInstallWaits;

final readonly class ReportInteractiveLaunchHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private InteractiveRuns $interactiveRuns,
        private WorkSubjectHandlers $subjects,
        private InitInstallWaits $installWaits,
        private ReportedWorkRequest $reportedWorkRequest,
    ) {
    }

    public function __invoke(ReportInteractiveLaunchCommand $command): ReportInteractiveLaunchResult
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new ReportInteractiveLaunchResult(null, created: false);
        }
        if (!$this->subjects->has($command->subject->type)) {
            return new ReportInteractiveLaunchResult(null, created: false, error: ReportInteractiveLaunchResult::UNKNOWN_SUBJECT_TYPE);
        }
        if (!$this->subjects->belongsTo($project, $command->subject)) {
            return new ReportInteractiveLaunchResult(null, created: false, error: ReportInteractiveLaunchResult::SUBJECT_NOT_FOUND);
        }
        $request = null === $command->workRequestId ? null : $this->reportedWorkRequest->find($command->workRequestId, $project, $command->subject);
        if (null !== $command->workRequestId && null === $request) {
            return new ReportInteractiveLaunchResult(null, created: false, error: ReportInteractiveLaunchResult::WORK_REQUEST_NOT_FOUND);
        }
        // A request that left its card has no card number, and neither has its run.
        $moved = null !== $request && $request->subjectType !== $command->subject->type;
        $subject = $request?->subject() ?? $command->subject;
        $cardNumber = $moved ? null : $command->cardNumber;

        [$run, $created] = match ($command->state) {
            WorkerRunState::Running => $this->interactiveRuns->recordLaunch(
                $project,
                $subject,
                $cardNumber,
                $command->sessionId,
                $command->workKind,
                $command->bridgeId,
                $command->workRequestId,
                $command->ruleId,
                $command->harness,
                $command->account,
                $command->model,
                $command->harnessSessionId,
            ),
            WorkerRunState::NotStarted => $this->interactiveRuns->recordLaunchFailure(
                $project,
                $subject,
                $cardNumber,
                $command->sessionId,
                $command->workKind,
                $command->bridgeId,
                $command->failureReason ?? throw new \LogicException('A launch that failed carries its reason.'),
                $command->at,
                $command->workRequestId,
                $command->ruleId,
                $command->harness,
                $command->account,
                $command->model,
                $command->harnessSessionId,
            ),
            default => throw new \LogicException(\sprintf('A launch is running or not-started, never %s.', $command->state->value)),
        };

        // The mark follows the request also for a run that a stale close ended, because the wait goes on after it.
        if (WorkerRunState::Running === $command->state && null !== $command->awaitingInstall && WorkSubject::PROJECT === $command->subject->type) {
            $projectId = $project->id?->toRfc4122() ?? throw new \LogicException('A stored project has an id.');
            $command->awaitingInstall
                ? $this->installWaits->mark($command->sessionId->toRfc4122(), $projectId)
                : $this->installWaits->clear($command->sessionId->toRfc4122(), $projectId);
        }

        return new ReportInteractiveLaunchResult($run, $created);
    }
}
