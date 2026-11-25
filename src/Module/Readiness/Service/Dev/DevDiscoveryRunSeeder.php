<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds two discovery runs in the Lifecycle project. The latest one is requested, so the Workshop shows a
 * running discovery. The earlier one is done and has a worker run, which the Agents page labels "Discovery".
 * "Dev Project" has neither, so its two pages show the empty state.
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
        $project = $this->projects->find(DevProjectSeeder::lifecycleProjectId())
            ?? throw new \LogicException('The lifecycle project does not exist.');

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

    private function run(SeedContext $context, Project $project, Uuid $id, int $secondsAgo, DiscoveryRunState $state): void
    {
        if ($this->discoveryRuns->find($id) instanceof DiscoveryRun) {
            return;
        }

        $run = new DiscoveryRun($project, $context->at($secondsAgo));
        $run->state = $state;
        $run->endedAt = DiscoveryRunState::Done === $state ? $context->at(6600) : null;
        $context->ids->assign($run, $id);
        $this->em->persist($run);
        $this->em->flush();
    }
}
