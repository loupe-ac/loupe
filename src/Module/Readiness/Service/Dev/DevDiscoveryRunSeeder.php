<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Command\CancelDiscoveryHandler;
use App\Module\Readiness\Command\SubmitReadinessReportHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the discovery states of the preview pages, in six projects. @comment-budget-ignore
 * Lifecycle: the latest run is requested, so its readiness row offers Cancel. An earlier done run has a
 * worker run, which the Agents page labels "Discovery". "Setup done": a requested run behind a hidden guide,
 * so the Workshop shows the notice. "Inbox, not grouped": a run that the dev user cancelled.
 * "Acme web": a revising run with a report. "Acme api": a cancelled run whose account is gone.
 * "Dev Project (live board)": a reported run with a live report and a bridge that can run discovery, so its
 * row offers a new run. Its twin, the empty live board project, has no run.
 * "Dev Project" has no run, so its pages show the empty state. A live run here has no work request and no
 * open worker run, so no timeout ends it.
 */
#[When('dev')]
#[When('test')]
final readonly class DevDiscoveryRunSeeder implements DevSeederInterface
{
    public const string KEY = 'readiness-discovery-run';

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projects,
        private DiscoveryRunRepository $discoveryRuns,
        private WorkerRunRepository $workerRuns,
        private DocumentRepository $documents,
        private BridgeRepository $bridges,
        private DocumentTagApplier $tagApplier,
        private MarkdownRenderer $renderer,
    ) {
    }

    public static function runId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/lifecycle');
    }

    public static function endedRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/lifecycle-ended');
    }

    public static function noticeRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/setup-done');
    }

    public static function cancelledRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/inbox-flat-cancelled');
    }

    public static function workerRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-worker-run/lifecycle');
    }

    public static function revisingRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/revising');
    }

    public static function revisingReportId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-report/revising');
    }

    public static function orphanedCancelRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/cancelled-by-deleted-account');
    }

    public static function reportedRunId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-run/reported');
    }

    public static function waitingReportId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-report/reported');
    }

    public static function reportBridgeId(): Uuid
    {
        return DevSeedId::of('readiness/discovery-bridge/reported');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $project = $this->project(DevProjectSeeder::lifecycleProjectId());

        $noticeProject = $this->project(DevProjectSeeder::setupDoneProjectId());
        if (null === $noticeProject->readinessGuideHiddenAt) {
            $noticeProject->readinessGuideHiddenAt = $context->at(300);
            $this->em->flush();
        }
        $this->run($context, $noticeProject, self::noticeRunId(), 180, DiscoveryRunState::Requested);

        $cancelledProject = $this->project(DevProjectSeeder::inboxFlatProjectId());
        $this->run($context, $cancelledProject, self::cancelledRunId(), 300, DiscoveryRunState::Failed, $context->devUser(), cancelled: true);

        // A revising row also links its report, so it holds two actions. These two projects belong to the installer account.
        $revisingProject = $this->project(DevProjectSeeder::installerWebProjectId());
        $revising = $this->run($context, $revisingProject, self::revisingRunId(), 240, DiscoveryRunState::Revising);
        if (null === $revising->reportDocument) {
            $revising->reportDocument = $this->report($context, $revisingProject, self::revisingReportId(), 'Discovery report: in revision', 'The seeded report of a discovery run that a worker revises.');
            $this->em->flush();
        }
        // No canceller, as after the deletion of that account.
        $orphanedProject = $this->project(DevProjectSeeder::installerApiProjectId());
        $this->run($context, $orphanedProject, self::orphanedCancelRunId(), 300, DiscoveryRunState::Failed, cancelled: true);

        $reportedProject = $this->project(DevProjectSeeder::liveBoardProjectId());
        $reported = $this->run($context, $reportedProject, self::reportedRunId(), 220, DiscoveryRunState::Reported);
        if (null === $reported->reportDocument) {
            $reported->reportDocument = $this->report($context, $reportedProject, self::waitingReportId(), 'Discovery report: waits for review', 'The seeded report of a discovery run that waits for a verdict.');
            $this->em->flush();
        }
        $this->discoveryBridge($context, $reportedProject);

        $this->run($context, $project, self::runId(), 120, DiscoveryRunState::Requested);
        $this->run($context, $project, self::endedRunId(), 270, DiscoveryRunState::Done);

        // An ended worker run: an open run of a bridge that never beats times out, and that end fails a live run.
        if (!$this->workerRuns->find(self::workerRunId()) instanceof WorkerRun) {
            $endedAt = $context->at(200);
            $worker = new WorkerRun(
                project: $project,
                bridgeId: DevSeedId::of('readiness/discovery-bridge'),
                subjectType: DiscoveryRun::SUBJECT_TYPE,
                subjectId: self::endedRunId(),
                cardNumber: null,
                workKind: 'discovery',
                state: WorkerRunState::Succeeded,
                runKey: DevSeedId::of('readiness/discovery-worker-run-key'),
                startedAt: $context->at(250),
                endedAt: $endedAt,
                exitCode: 0,
                hasResult: true,
                output: 'The readiness report is in review.',
                receivedAt: $endedAt,
            );
            $context->ids->assign($worker, self::workerRunId());
            $this->em->persist($worker);
            $this->em->flush();
        }
    }

    private function project(Uuid $id): Project
    {
        return $this->projects->find($id) ?? throw new \LogicException(\sprintf('The demo project %s does not exist.', $id));
    }

    private function run(SeedContext $context, Project $project, Uuid $id, int $secondsAgo, DiscoveryRunState $state, ?User $cancelledBy = null, bool $cancelled = false): DiscoveryRun
    {
        $existing = $this->discoveryRuns->find($id);
        if ($existing instanceof DiscoveryRun) {
            return $existing;
        }

        $run = new DiscoveryRun($project, $context->at($secondsAgo));
        $run->state = $state;
        $run->endedAt = DiscoveryRunState::Done === $state ? $context->at(200) : null;
        if ($cancelled) {
            $run->failureReason = CancelDiscoveryHandler::REASON;
            $run->cancelledBy = $cancelledBy;
            $run->endedAt = $context->at($secondsAgo - 60);
        }
        $context->ids->assign($run, $id);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** The row offers a new run only while a bridge is live, so this one stays live for years. */
    private function discoveryBridge(SeedContext $context, Project $project): void
    {
        if (null !== $this->bridges->findOneByOwnerAndId($project->owner, self::reportBridgeId())) {
            return;
        }

        $bridge = new Bridge($project->owner, self::reportBridgeId(), [(string) $project->id], '1.4.0', $context->now->modify('+10 years'));
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_WAITS, Bridge::CAPABILITY_APP_PROMPTS];
        $this->em->persist($bridge);
        $this->em->flush();
    }

    private function report(SeedContext $context, Project $project, Uuid $id, string $title, string $markdown): Document
    {
        $existing = $this->documents->find($id);
        if ($existing instanceof Document) {
            return $existing;
        }

        $document = new Document($project->owner, $project, $title, $context->at(200));
        $document->addVersion($markdown, $this->renderer->render($markdown));
        $this->tagApplier->apply($document, [SubmitReadinessReportHandler::TAG]);
        $context->ids->assign($document, $id);
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
