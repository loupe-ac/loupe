<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\OpenRouter\Command\ShowProjectOpenRouterCommand;
use App\Module\OpenRouter\Command\ShowProjectOpenRouterHandler;
use App\Module\OpenRouter\Form\OpenRouterKeyFormType;
use App\Module\OpenRouter\Form\OpenRouterKeyRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/openrouter',
    name: 'app_project_openrouter',
    methods: ['GET'],
)]
final class ShowProjectOpenRouterController extends AppController
{
    public function __construct(
        private readonly ShowProjectOpenRouterHandler $showProject,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $view = ($this->showProject)(new ShowProjectOpenRouterCommand($project));
        $injectedForm = $this->getInjectedFormView($request, 'keyForm');
        $keyForm = $injectedForm ?? $this->createForm(OpenRouterKeyFormType::class, new OpenRouterKeyRequest())->createView();

        return $this->render('@OpenRouter/show_project_openrouter.html.twig', [
            'project' => $project,
            'view' => $view,
            'keyForm' => $keyForm,
            'replaceOpen' => null !== $injectedForm || $request->query->has('replace'),
            'notManagementKey' => true === $request->attributes->get('notManagementKey'),
        ]);
    }
}
