<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Command\CancelDiscoveryHandler;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the discovery states of the preview pages, in three projects. @comment-budget-ignore
 * Lifecycle: the latest run is requested, so its readiness row offers Cancel. An earlier done run has a
 * worker run, which the Agents page labels "Discovery". "Setup done": a requested run behind a hidden guide,
 * so the Workshop shows the notice. "Inbox, not grouped": a run that the dev user cancelled.
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
            $noticeProject->readinessGuideHiddenAt = $context->at(600);
            $this->em->flush();
        }
        $this->run($context, $noticeProject, self::noticeRunId(), 180, DiscoveryRunState::Requested);

        $cancelledProject = $this->project(DevProjectSeeder::inboxFlatProjectId());
        $this->run($context, $cancelledProject, self::cancelledRunId(), 900, DiscoveryRunState::Failed, $context->devUser());

        $this->run($context, $project, self::runId(), 120, DiscoveryRunState::Requested);
        $this->run($context, $project, self::endedRunId(), 7200, DiscoveryRunState::Done);

        // An ended worker run: an open run of a bridge that never beats times out, and that end fails a live run.
        if (!$this->workerRuns->find(self::workerRunId()) instanceof WorkerRun) {
            $endedAt = $context->at(6600);
            $worker = new WorkerRun(
                project: $project,
                bridgeId: DevSeedId::of('readiness/discovery-bridge'),
                subjectType: DiscoveryRun::SUBJECT_TYPE,
                subjectId: self::endedRunId(),
                cardNumber: null,
                workKind: 'discovery',
                state: WorkerRunState::Succeeded,
                runKey: DevSeedId::of('readiness/discovery-worker-run-key'),
                startedAt: $context->at(7140),
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

    private function run(SeedContext $context, Project $project, Uuid $id, int $secondsAgo, DiscoveryRunState $state, ?User $cancelledBy = null): void
    {
        if ($this->discoveryRuns->find($id) instanceof DiscoveryRun) {
            return;
        }

        $run = new DiscoveryRun($project, $context->at($secondsAgo));
        $run->state = $state;
        $run->endedAt = DiscoveryRunState::Done === $state ? $context->at(6600) : null;
        if (null !== $cancelledBy) {
            $run->failureReason = CancelDiscoveryHandler::REASON;
            $run->cancelledBy = $cancelledBy;
            $run->endedAt = $context->at($secondsAgo - 60);
        }
        $context->ids->assign($run, $id);
        $this->em->persist($run);
        $this->em->flush();
    }
}
