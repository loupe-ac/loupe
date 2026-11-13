<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\StartGitHubAppInstallCommand;
use App\Module\GitHub\Command\StartGitHubAppInstallHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/** "Install on another account": starts a GitHub install. It replaces a live picker session and keeps the init session of this project. */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/install/account',
    name: 'app_github_app_install',
    methods: ['GET'],
)]
class InstallGitHubAppController extends AppController
{
    public function __construct(
        private readonly StartGitHubAppInstallHandler $startInstall,
        private readonly GitHubInstallSession $installSession,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $pending = $this->installSession->pending();
        $init = null !== $pending && $pending->projectId === (string) $project->id ? $pending->init : null;

        try {
            return $this->redirect(($this->startInstall)(new StartGitHubAppInstallCommand($project, init: $init)));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        }
    }
}
