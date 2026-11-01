<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Controller;

use App\Controller\AppController;
use App\Module\OpenRouter\Command\RemoveOpenRouterKeyCommand;
use App\Module\OpenRouter\Command\RemoveOpenRouterKeyHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('openrouter-key-remove')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/settings/openrouter/remove',
    name: 'app_project_openrouter_remove',
    methods: ['POST'],
)]
final class RemoveProjectOpenRouterKeyController extends AppController
{
    public function __construct(
        private readonly RemoveOpenRouterKeyHandler $removeKey,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        ($this->removeKey)(new RemoveOpenRouterKeyCommand($project));
        $this->addFlash('success', $this->translator->trans('openrouter.flash.removed'));

        return $this->redirectToRoute('app_project_openrouter', ['id' => $project->id]);
    }
}
