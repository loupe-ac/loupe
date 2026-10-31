<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\SaveOpenRouterKeyHandler;
use App\Module\OpenRouter\Form\OpenRouterKeyFormType;
use App\Module\OpenRouter\Form\OpenRouterKeyRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/openrouter',
    name: 'app_project_openrouter_save',
    methods: ['POST'],
)]
final class SaveProjectOpenRouterKeyController extends AppController
{
    public function __construct(
        private readonly SaveOpenRouterKeyHandler $saveKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, Request $request): Response
    {
        $form = $this->createForm(OpenRouterKeyFormType::class, new OpenRouterKeyRequest());
        $form->handleRequest($request);
        $notManagementKey = false;

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            \assert($data instanceof OpenRouterKeyRequest);

            try {
                ($this->saveKey)(new SaveOpenRouterKeyCommand($project, $data->secret ?? throw new \LogicException('The key is required after form validation.')));
                $this->addFlash('success', $this->translator->trans('openrouter.flash.saved'));

                return $this->redirectToRoute('app_project_openrouter', ['id' => $project->id]);
            } catch (DomainErrors $e) {
                $this->applyDomainErrors($form, $e);
                $notManagementKey = 'openrouter.error.not_management_key' === ($e->errors['secret'] ?? null);
            }
        }

        return $this->forward(ShowProjectOpenRouterController::class, [
            'id' => (string) $project->id,
            'keyForm' => $form->createView(),
            'notManagementKey' => $notManagementKey,
        ])->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
