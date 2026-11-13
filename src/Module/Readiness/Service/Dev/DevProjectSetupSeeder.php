<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Contract\InitRuns;
use App\Module\Bridge\Service\InteractiveRuns;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Gives the projects of the other setup page states their facts. The state with a repository
 * and no init run belongs to the project that is not set up, and the done state to any seeded project.
 */
#[When('dev')]
#[When('test')]
final readonly class DevProjectSetupSeeder implements DevSeederInterface
{
    public const string KEY = 'project-setup';

    public function __construct(
        private ProjectRepository $projects,
        private ForgeRepositoryRepository $forgeRepositories,
        private InitRuns $initRuns,
        private InteractiveRuns $interactiveRuns,
        private ProjectMilestones $milestones,
        private EntityManagerInterface $em,
    ) {
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
        $running = $this->prepare($context, DevProjectSeeder::setupInitRunningProjectId(), 'acme/setup-running');
        if ($running instanceof Project && $this->hasNoInitRun($running)) {
            $this->launchInit($running);
        }

        $stopped = $this->prepare($context, DevProjectSeeder::setupInitStoppedProjectId(), 'acme/setup-stopped');
        if ($stopped instanceof Project && $this->hasNoInitRun($stopped)) {
            $run = $this->launchInit($stopped);
            $this->interactiveRuns->closeById($stopped, $run);
        }

        $pending = $this->prepare($context, DevProjectSeeder::setupBridgePendingProjectId(), 'acme/setup-pending');
        if ($pending instanceof Project && $this->hasNoInitRun($pending)) {
            $run = $this->launchInit($pending);
            $this->interactiveRuns->closeById($pending, $run);
            $this->milestones->reach($pending->requireId(), ProjectMilestones::INIT_FINISHED);
        }
    }

    private function prepare(SeedContext $context, Uuid $projectId, string $path): ?Project
    {
        $project = $this->projects->find($projectId);
        if (!$project instanceof Project) {
            return null;
        }

        $id = DevSeedId::of('project-setup/'.$path);
        if (!$this->forgeRepositories->find($id) instanceof ForgeRepository) {
            $repository = new ForgeRepository($project, 'github', 'project-setup/'.$path, $path, ForgeRepositorySource::Installation, '1');
            $context->ids->assign($repository, $id);
            $this->em->persist($repository);
            $this->em->flush();
        }

        return $project;
    }

    private function hasNoInitRun(Project $project): bool
    {
        $id = $project->requireId();

        return !$this->initRuns->isOpen($id) && !$this->initRuns->hasClosed($id);
    }

    private function launchInit(Project $project): Uuid
    {
        $id = $project->requireId();
        [$run] = $this->interactiveRuns->recordLaunch(
            $project,
            new WorkSubject(WorkSubject::PROJECT, $id),
            null,
            DevSeedId::of('project-setup/session/'.$id->toRfc4122()),
            InitRuns::WORK_KIND,
            DevSeedId::of('project-setup/bridge'),
        );

        return $run->id ?? throw new \LogicException('A stored run has an id.');
    }
}
