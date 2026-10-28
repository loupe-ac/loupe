<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Command\CheckGitHubRepositoryCommand;
use App\Module\GitHub\Command\CheckGitHubRepositoryHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Check again: reads GitHub in the request, so the page that follows shows the fresh result. */
#[CsrfToken('github-repository-check')]
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/repositories/{repositoryId}/check',
    name: 'app_github_repository_check',
    requirements: ['repositoryId' => Requirement::UUID],
    methods: ['POST'],
)]
final class CheckGitHubRepositoryController extends AppController
{
    public function __construct(
        private readonly CheckGitHubRepositoryHandler $checkRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Project $project,
        // The alias moves the raw project id to `project`, and scoping to it makes a foreign id a 404.
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(repositoryId, project)')] ForgeRepository $repository,
    ): Response {
        try {
            $result = ($this->checkRepository)(new CheckGitHubRepositoryCommand($project, $repository));
            if ($result->throttled) {
                $this->addFlash('warning', null === $result->lastReadAt
                    ? $this->translator->trans('github.repositories.flash.check_throttled_untimed')
                    : $this->translator->trans('github.repositories.flash.check_throttled', ['%time%' => $result->lastReadAt->format('H:i')]));
            }
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }
        }

        return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
    }
}
