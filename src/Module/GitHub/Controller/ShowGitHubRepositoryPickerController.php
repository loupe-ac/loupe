<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\ShowGitHubRepositoryPickerCommand;
use App\Module\GitHub\Command\ShowGitHubRepositoryPickerHandler;
use App\Module\GitHub\Form\PickRepositoriesFormType;
use App\Module\GitHub\Form\PickRepositoriesRequest;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The repository picker. It lists on every request, so "Refresh list" is a
 * plain link to this page. An expired sign-in sends the owner to GitHub again.
 */
#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/repositories',
    name: 'app_github_repositories_picker',
    methods: ['GET'],
)]
class ShowGitHubRepositoryPickerController extends AppController
{
    public function __construct(
        private readonly ShowGitHubRepositoryPickerHandler $showPicker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        try {
            $picker = ($this->showPicker)(new ShowGitHubRepositoryPickerCommand($project));
        } catch (DomainErrors $e) {
            if (\array_key_exists('token', $e->errors)) {
                return $this->redirectToRoute('app_github_repositories_start', ['id' => (string) $project->id]);
            }

            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        }

        $response = $this->render('@GitHub/show_github_repository_picker.html.twig', [
            'project' => $project,
            'picker' => $picker,
            'form' => $this->createForm(PickRepositoriesFormType::class, new PickRepositoriesRequest(), [
                'action' => $this->generateUrl('app_github_repositories_add', ['id' => (string) $project->id]),
            ])->createView(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
