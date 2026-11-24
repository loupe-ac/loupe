<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ChangeWaitLimitCommand;
use App\Module\Bridge\Command\ChangeWaitLimitHandler;
use App\Module\Bridge\Form\ChangeWaitLimitFormType;
use App\Module\Bridge\Form\ChangeWaitLimitRequest;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[Route(
    '/projects/{id:project}/cards/{cardId}/wait/limit',
    name: 'app_project_card_wait_limit',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class ChangeWaitLimitController extends AppController
{
    public function __construct(
        private readonly ChangeWaitLimitHandler $handler,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, Project $project, string $cardId): Response
    {
        $data = new ChangeWaitLimitRequest();
        $form = $this->createForm(ChangeWaitLimitFormType::class, $data);
        $form->handleRequest($request);

        // The limit form sits in the status box of the card page, so a refusal comes back as a flash on that page.
        $errors = [];
        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }
        } else {
            try {
                ($this->handler)(new ChangeWaitLimitCommand($project, Uuid::fromString($cardId), $data->minutes ?? throw new \LogicException('minutes required after validation')));
            } catch (DomainErrors $e) {
                $errors = array_map($this->translator->trans(...), array_values($e->errors));
            }
        }
        foreach ($errors as $message) {
            $this->addFlash('error', $message);
        }

        return $this->redirectToRoute('app_board_card', [
            'projectId' => (string) $project->id,
            'cardId' => $cardId,
        ], Response::HTTP_SEE_OTHER);
    }
}
