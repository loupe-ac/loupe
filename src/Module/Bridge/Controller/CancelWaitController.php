<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\CancelWaitCommand;
use App\Module\Bridge\Command\CancelWaitHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('card-worker-wait')]
#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/cards/{cardId}/wait/cancel',
    name: 'app_project_card_wait_cancel',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class CancelWaitController extends AppController
{
    public function __construct(
        private readonly CancelWaitHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Project $project, string $cardId): Response
    {
        try {
            ($this->handler)(new CancelWaitCommand($project, Uuid::fromString($cardId)));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $key) {
                $this->addFlash('error', $this->translator->trans($key));
            }
        }

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $project->id,
            'cardId' => $cardId,
        ], Response::HTTP_SEE_OTHER);
    }
}
