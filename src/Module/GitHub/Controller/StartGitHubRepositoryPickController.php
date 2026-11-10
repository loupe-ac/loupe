<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\StartGitHubRepositoryPickCommand;
use App\Module\GitHub\Command\StartGitHubRepositoryPickHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add repositories": signs the owner in to GitHub, then opens the picker. The
 * path is the one `loupe init` already prints, and the optional `repository`
 * names a path to tick.
 */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/install',
    name: 'app_github_repositories_start',
    methods: ['GET'],
)]
class StartGitHubRepositoryPickController extends AppController
{
    public function __construct(
        private readonly StartGitHubRepositoryPickHandler $startPick,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        try {
            return $this->redirect(($this->startPick)(new StartGitHubRepositoryPickCommand(
                $project,
                $request->query->has('repository') ? $request->query->getString('repository') : null,
                $this->generateUrl('app_github_app_callback', [], UrlGeneratorInterface::ABSOLUTE_URL),
            )));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        }
    }
}
