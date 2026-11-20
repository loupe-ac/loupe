<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Command\CheckGitHubRepositoryCommand;
use App\Module\GitHub\Command\CheckGitHubRepositoryHandler;
use App\Module\GitHub\Command\ShowRepositoryRowCommand;
use App\Module\GitHub\Command\ShowRepositoryRowHandler;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/**
 * Check again: reads GitHub in the request. A form on the Repositories page sends `inline` and gets the fresh row
 * as a stream. Any other form, such as the inbox notice, gets the redirect to that page.
 */
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
        private readonly ShowRepositoryRowHandler $showRepositoryRow,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        Project $project,
        // The alias moves the raw project id to `project`, and scoping to it makes a foreign id a 404.
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(repositoryId, project)')] ForgeRepository $repository,
    ): Response {
        $flashes = [];
        try {
            $result = ($this->checkRepository)(new CheckGitHubRepositoryCommand($project, $repository));
            if ($result->throttled) {
                $flashes[] = ['warning', null === $result->lastReadAt
                    ? $this->translator->trans('github.repositories.flash.check_throttled_untimed')
                    : $this->translator->trans('github.repositories.flash.check_throttled', ['%time%' => $result->lastReadAt->format('H:i')])];
            }
        } catch (DomainErrors $e) {
            foreach ($e->errors as $translationKey) {
                $flashes[] = ['error', $this->translator->trans($translationKey)];
            }
        }

        if ($request->query->has('inline') && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $body = $this->renderView('@GitHub/_repository_row.stream.html.twig', [
                'row' => ($this->showRepositoryRow)(new ShowRepositoryRowCommand($repository)),
                'project' => $project,
            ]);
            foreach ($flashes as [$severity, $message]) {
                $body .= $this->renderView('_flash.stream.html.twig', ['severity' => $severity, 'message' => $message]);
            }

            return new Response($body, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        foreach ($flashes as [$severity, $message]) {
            $this->addFlash($severity, $message);
        }

        return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
    }
}
