<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Command\UpdateProjectCommand;
use App\Module\Project\Command\UpdateProjectHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\DeleteProjectFormType;
use App\Module\Project\Form\UpdateProjectRequest;
use App\Module\Project\ProjectEventType;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/edit',
    name: 'app_project_edit',
    methods: ['GET', 'POST'],
)]
class EditProjectController extends AppController
{
    public function __construct(
        private readonly UpdateProjectHandler $updateProjectHandler,
    ) {
    }

    public function __invoke(Request $request, Project $project): Response
    {
        $data = UpdateProjectRequest::fromProject($project);
        $form = $this->createForm(CreateProjectFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                ($this->updateProjectHandler)(new UpdateProjectCommand(
                    project: $project,
                    name: $data->name ?? '',
                    domain: $data->domain ?? '',
                    searchLanguage: $data->searchLanguage ?? throw new \LogicException('search language required after validation'),
                    actor: ProjectEventType::ACTOR_HUMAN,
                    description: $data->description ?? '',
                ));

                return $this->redirectToRoute('app_projects');
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
            }
        }

        return $this->renderFormResponse('@Project/edit_project.html.twig', $form, [
            'project' => $project,
            'deleteForm' => $this->getInjectedFormView($request, 'deleteForm')
                ?? $this->createForm(DeleteProjectFormType::class)->createView(),
        ]);
    }
}
