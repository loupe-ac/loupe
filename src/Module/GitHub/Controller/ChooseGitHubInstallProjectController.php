<?php

declare(strict_types=1);

namespace App\Module\GitHub\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\GitHub\Command\ChooseGitHubInstallProjectCommand;
use App\Module\GitHub\Command\ChooseGitHubInstallProjectHandler;
use App\Module\GitHub\Command\ShowGitHubInstallProjectCommand;
use App\Module\GitHub\Command\ShowGitHubInstallProjectHandler;
use App\Module\GitHub\Form\ChooseInstallProjectFormType;
use App\Module\GitHub\Form\ChooseInstallProjectRequest;
use App\Module\GitHub\Service\PickerReturn;
use App\Module\Project\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where a person lands after an install that began on github.com, once GitHub listed the installation for them.
 * The person picks a project they own, or names a new one. The handler checks the project against the voter,
 * because the route holds no project.
 */
#[Route(
    '/github/app/project',
    name: 'app_github_app_project',
    methods: ['GET', 'POST'],
)]
class ChooseGitHubInstallProjectController extends AppController
{
    public function __construct(
        private readonly ShowGitHubInstallProjectHandler $showChoice,
        private readonly ChooseGitHubInstallProjectHandler $chooseProject,
        private readonly PickerReturn $pickerReturn,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The firewall guarantees a signed-in person.');
        }

        try {
            $choice = ($this->showChoice)(new ShowGitHubInstallProjectCommand($user));
        } catch (DomainErrors $e) {
            return $this->leave($e);
        }

        if (null === $choice) {
            $this->addFlash('error', $this->translator->trans('github.app.flash.expired'));

            return $this->redirectToRoute('app_projects');
        }

        $data = new ChooseInstallProjectRequest(
            project: array_key_first($choice->optionsById()) ?? ChooseInstallProjectRequest::NEW_PROJECT,
            name: mb_substr($choice->accountLogin, 0, Project::MAX_NAME_LENGTH),
        );
        $form = $this->createForm(ChooseInstallProjectFormType::class, $data, [
            'project_ids' => [...array_keys($choice->optionsById()), ChooseInstallProjectRequest::NEW_PROJECT],
        ])->handleRequest($request);

        $chosen = null;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $chosen = ($this->chooseProject)(new ChooseGitHubInstallProjectCommand($user, $data->projectId(), $data->name));
            } catch (DomainErrors $e) {
                if (!array_any(array_keys($e->errors), static fn (string $field): bool => $form->has($field))) {
                    return $this->leave($e);
                }

                $this->applyDomainErrors($form, $e);
            }
        }

        if (null !== $chosen) {
            $projectId = (string) $chosen->project->id;
            if ([] !== $chosen->refused) {
                $this->addFlash('warning', $this->translator->trans('github.picker.flash.refused', [
                    '%count%' => \count($chosen->refused),
                    '%repositories%' => implode(', ', $chosen->refused),
                ]));
            }

            if (null === $chosen->connected) {
                return $this->redirectToRoute('app_project_repositories_settings', ['id' => $projectId]);
            }

            $this->addFlash('success', $this->translator->trans('github.picker.flash.connected', ['%repository%' => $chosen->connected]));
            $target = $this->pickerReturn->target($chosen->project, null, [$chosen->connected]);

            return $this->redirectToRoute($target->route, $target->parameters);
        }

        return $this->renderFormResponse('@GitHub/choose_github_install_project.html.twig', $form, [
            'choice' => $choice,
            'options' => $choice->optionsById(),
        ]);
    }

    private function leave(DomainErrors $e): Response
    {
        foreach ($e->errors as $translationKey) {
            $this->addFlash('error', $this->translator->trans($translationKey));
        }

        return $this->redirectToRoute('app_projects');
    }
}
