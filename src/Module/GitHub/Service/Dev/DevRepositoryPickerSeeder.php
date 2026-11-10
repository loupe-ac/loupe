<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Holds two repositories of the preview picker: one in the lifecycle project and
 * one in the project of another account. The third stays free, and the dev
 * project holds none, so its Repositories tab is empty.
 */
#[When('dev')]
#[When('test')]
final readonly class DevRepositoryPickerSeeder implements DevSeederInterface
{
    public const string KEY = 'github-repository-picker';

    public function __construct(
        private ProjectRepository $projects,
        private ForgeRepositoryRepository $forgeRepositories,
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
        return [DevProjectSeeder::KEY, DevRepositoryCheckSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        $this->hold($context, DevProjectSeeder::lifecycleProjectId(), DevGitHubPickerClient::HELD_REPOSITORY, 'acme/held-app');
        $this->hold($context, DevProjectSeeder::keysProjectId(), DevGitHubPickerClient::OTHER_PROJECT_REPOSITORY, 'acme/elsewhere-app');
        $this->em->flush();
    }

    private function hold(SeedContext $context, Uuid $projectId, int $repositoryId, string $path): void
    {
        $project = $this->projects->find($projectId);
        $id = DevSeedId::of('github-picker/'.$repositoryId);
        if (!$project instanceof Project || $this->forgeRepositories->find($id) instanceof ForgeRepository) {
            return;
        }

        $repository = new ForgeRepository($project, 'github', (string) $repositoryId, $path, ForgeRepositorySource::Installation, (string) DevRepositoryCheckSeeder::INSTALLATION_ID);
        $context->ids->assign($repository, $id);
        $this->em->persist($repository);
    }
}
