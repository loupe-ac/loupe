<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\RepositoryCheckTopics;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubFreeRepositories;
use App\Module\GitHub\Service\GitHubRepositoryRows;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\GitHub\View\RepositoryRow;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The repository rows of the Repositories settings page, with their checks.
 * The Project module names it in its template only, so it never imports this
 * module. Each render queues a check for every repository whose last read is
 * stale.
 *
 * Props: `project`, passed as an object with `:project`.
 */
#[AsTwigComponent(name: 'GitHubRepositories')]
final class GitHubRepositoriesComponent
{
    public Project $project;

    public bool $visible = false;

    public bool $connectionsAvailable = false;

    public bool $appConfigured = false;

    /** @var list<RepositoryRow> */
    public array $rows = [];

    public string $topic = '';

    /** The repositories the App reaches through this project's installations that no project holds. */
    public int $freeCount = 0;

    /** False when an installation holds more repositories than one read lists, so the count is a lower bound. */
    public bool $freeComplete = true;

    public function __construct(
        private readonly Security $security,
        private readonly ForgeRepositoryRepository $forgeRepositories,
        private readonly GitHubRepositoryRows $repositoryRows,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly HookSecretKey $hookSecretKey,
        private readonly RepositoryCheckTopics $topics,
        private readonly GitHubFreeRepositories $freeRepositories,
    ) {
    }

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->visible = $this->security->isGranted(GitHubConnectionVoter::MANAGE, $project);
        if (!$this->visible) {
            return;
        }

        $this->appConfigured = $this->appConfiguration->isConfigured();
        $this->connectionsAvailable = $this->appConfigured || $this->hookSecretKey->isReadable();
        $this->topic = $this->topics->forProject($project->id ?? throw new \LogicException('A persisted project has an id.'));
        foreach ($this->forgeRepositories->findByProject($project) as $repository) {
            $this->rows[] = $this->repositoryRows->forRepository($repository, requestCheck: true);
        }
        if ([] !== $this->rows) {
            $free = $this->freeRepositories->forProject($project);
            $this->freeCount = \count($free->repositories);
            $this->freeComplete = $free->complete;
        }
    }
}
