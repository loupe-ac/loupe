<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Exception\DomainErrors;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\RepositoryCheckTopics;
use App\Module\GitHub\Command\ShowGitHubRepositoryPickerCommand;
use App\Module\GitHub\Command\ShowGitHubRepositoryPickerHandler;
use App\Module\GitHub\Form\PickRepositoriesFormType;
use App\Module\GitHub\Form\PickRepositoriesRequest;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubRepositoryRows;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\GitHub\View\GitHubRepositoryPickerView;
use App\Module\GitHub\View\RepositoryRow;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The Repositories settings page: the repository rows with their checks, and
 * the picker while the owner holds a live GitHub sign-in for the project. The
 * Project module names it in its template only, so it never imports this
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

    /** What the owner's GitHub user reaches, or null with no live sign-in. */
    public ?GitHubRepositoryPickerView $picker = null;

    public ?FormView $pickForm = null;

    /** The translation key of a GitHub failure that ended the sign-in. */
    public ?string $pickerFailure = null;

    public function __construct(
        private readonly Security $security,
        private readonly ForgeRepositoryRepository $forgeRepositories,
        private readonly GitHubRepositoryRows $repositoryRows,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly HookSecretKey $hookSecretKey,
        private readonly RepositoryCheckTopics $topics,
        private readonly ShowGitHubRepositoryPickerHandler $showPicker,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
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

        try {
            $this->picker = ($this->showPicker)(new ShowGitHubRepositoryPickerCommand($project));
        } catch (DomainErrors $e) {
            $this->pickerFailure = array_first($e->errors);
        }

        if (null !== $this->picker) {
            $this->pickForm = $this->formFactory->create(PickRepositoriesFormType::class, new PickRepositoriesRequest(), [
                'action' => $this->urls->generate('app_github_repositories_add', ['id' => (string) $project->id]),
            ])->createView();
        }
    }
}
