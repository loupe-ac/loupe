<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Module\GitHub\Command\CancelGitHubRepositoryPickCommand;
use App\Module\GitHub\Command\CancelGitHubRepositoryPickHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Leaves the picker and removes the owner's GitHub token from the session. */
#[CsrfToken('github-repositories-cancel')]
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/repositories/cancel',
    name: 'app_github_repositories_cancel',
    methods: ['POST'],
)]
class CancelGitHubRepositoryPickController extends AppController
{
    public function __construct(
        private readonly CancelGitHubRepositoryPickHandler $cancelPick,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        ($this->cancelPick)(new CancelGitHubRepositoryPickCommand($project));

        return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
    }
}
