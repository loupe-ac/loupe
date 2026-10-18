<?php

declare(strict_types=1);

namespace App\Module\GitHub\Twig\Components;

use App\Module\GitHub\Form\ChangeGitIdentityManagedFormType;
use App\Module\GitHub\Form\ChangeGitIdentityManagedRequest;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubGitTokenIssuer;
use App\Module\GitHub\Service\GitHubGitTokenRefused;
use App\Module\GitHub\Service\GitIdentityLine;
use App\Module\GitHub\Service\GitIdentityLines;
use App\Module\Project\Entity\Project;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The Git identity panel of the Connections page: the switch that lets Loupe
 * manage who agents push as, and one line per machine. The Project module
 * names it in its template only, so it never imports this module.
 *
 * Props: `project`, passed as an object with `:project`.
 */
#[AsTwigComponent(name: 'GitIdentity')]
final class GitIdentityComponent
{
    public Project $project;

    public bool $visible = false;

    public ?FormView $form = null;

    /** Why the agents of the project cannot get a push token, when the project asks for one. */
    public ?GitHubGitTokenRefused $pushRefusal = null;

    /** @var list<GitIdentityLine> */
    public array $lines = [];

    public function __construct(
        private readonly Security $security,
        private readonly FormFactoryInterface $formFactory,
        private readonly UrlGeneratorInterface $urls,
        private readonly GitHubAppConfiguration $appConfiguration,
        private readonly GitHubGitTokenIssuer $gitTokenIssuer,
        private readonly GitIdentityLines $identityLines,
    ) {
    }

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->visible = $this->security->isGranted(GitHubConnectionVoter::MANAGE, $project);
        if (!$this->visible) {
            return;
        }

        $this->form = $this->formFactory->create(
            ChangeGitIdentityManagedFormType::class,
            new ChangeGitIdentityManagedRequest($project->gitIdentityManaged),
            ['action' => $this->urls->generate('app_project_git_identity', ['id' => (string) $project->id])],
        )->createView();
        if ($project->gitIdentityManaged) {
            $this->pushRefusal = $this->appConfiguration->isConfigured()
                ? $this->gitTokenIssuer->refusal($project)
                : new GitHubGitTokenRefused(GitHubGitTokenRefused::APP_NOT_CONFIGURED);
        }
        $this->lines = $this->identityLines->forProject($project);
    }
}
