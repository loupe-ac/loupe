<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\GitHub\Command\AddGitHubRepositoriesCommand;
use App\Module\GitHub\Command\AddGitHubRepositoriesHandler;
use App\Module\GitHub\Form\PickRepositoriesFormType;
use App\Module\GitHub\Form\PickRepositoriesRequest;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubInstallSession;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\InitInstallWaits;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(GitHubConnectionVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/github/repositories',
    name: 'app_github_repositories_add',
    methods: ['POST'],
)]
class AddGitHubRepositoriesController extends AppController
{
    public function __construct(
        private readonly AddGitHubRepositoriesHandler $addRepositories,
        private readonly TranslatorInterface $translator,
        private readonly GitHubInstallSession $installSession,
        private readonly InitInstallWaits $installWaits,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $tab = $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
        $data = new PickRepositoriesRequest();
        $form = $this->createForm(PickRepositoriesFormType::class, $data)->handleRequest($request);
        $picks = $data->picks();
        if (!$form->isSubmitted() || !$form->isValid() || [] === $picks) {
            $this->addFlash('error', $this->translator->trans('github.picker.flash.nothing_ticked'));

            return $tab;
        }

        // The handler clears the picker session, so the init value is read first.
        $pending = $this->installSession->pending();
        $init = null !== $pending && $pending->projectId === (string) $project->id ? $pending->init : null;

        try {
            $added = ($this->addRepositories)(new AddGitHubRepositoriesCommand($project, $picks));
        } catch (DomainErrors $e) {
            if (\array_key_exists('token', $e->errors)) {
                return $this->redirectToRoute('app_github_repositories_start', ['id' => (string) $project->id]);
            }

            foreach ($e->errors as $translationKey) {
                $this->addFlash('error', $this->translator->trans($translationKey));
            }

            return $tab;
        }

        if ([] !== $added->added && $this->installWaits->isWaiting($init, (string) $project->id)) {
            return $this->redirectToRoute('app_github_init_handoff', ['id' => (string) $project->id, 'repository' => implode(',', $added->added)]);
        }

        if ([] !== $added->added) {
            $this->addFlash('success', $this->translator->trans('github.picker.flash.added', ['%count%' => \count($added->added)]));
        }

        if ([] !== $added->refused) {
            $this->addFlash('warning', $this->translator->trans('github.picker.flash.refused', [
                '%count%' => \count($added->refused),
                '%repositories%' => implode(', ', $added->refused),
            ]));
        }

        return $this->redirectToRoute('app_project_repositories_settings', ['id' => (string) $project->id]);
    }
}
