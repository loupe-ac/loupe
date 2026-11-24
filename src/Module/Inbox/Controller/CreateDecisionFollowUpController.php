<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\CreateDecisionFollowUpCommand;
use App\Module\Inbox\Command\CreateDecisionFollowUpHandler;
use App\Module\Inbox\Command\ShowCardDecisionsCommand;
use App\Module\Inbox\Command\ShowCardDecisionsHandler;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Security\InboxDecisionVoter;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Makes the follow-up card of a changed decision on a finished card, and links it to that card. */
#[CsrfToken('inbox-decision-follow-up')]
#[IsGranted(InboxDecisionVoter::CHANGE, subject: 'decision')]
#[Route(
    '/projects/{projectId}/inbox/decisions/{decisionId}/follow-up',
    name: 'app_inbox_decision_follow_up',
    methods: ['POST'],
)]
final class CreateDecisionFollowUpController extends AppController
{
    public function __construct(
        private readonly CreateDecisionFollowUpHandler $createFollowUp,
        private readonly ShowCardDecisionsHandler $showDecisions,
        private readonly InboxAvailability $inbox,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(decisionId, projectId)')] InboxDecision $decision,
    ): Response {
        $this->inbox->requireEnabled();
        $card = $decision->card;
        $back = $this->redirectToRoute('app_board_card', ['projectId' => $decision->project->id, 'cardId' => $card->id, 'tab' => 'decisions']);

        try {
            $followUp = ($this->createFollowUp)(new CreateDecisionFollowUpCommand(
                $decision,
                $this->translator->trans('inbox.decisions.follow_up.card_title', ['%label%' => $decision->label, '%number%' => $card->number]) ?: throw new \LogicException('The card title is translated.'),
                $this->translator->trans('inbox.decisions.follow_up.card_body', [
                    '%number%' => $card->number,
                    '%label%' => $decision->label,
                    '%choice%' => $decision->choice,
                    '%changed%' => $decision->changedChoice ?? '',
                    '%note%' => $decision->changedNote ?? '',
                ]),
                $card->type,
            ));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans((string) array_first($e->errors)));

            return $back;
        }

        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $this->redirectToRoute('app_board_card', ['projectId' => $followUp->project->id, 'cardId' => $followUp->id]);
        }

        return new Response(
            $this->renderView('@Inbox/_card_decisions.stream.html.twig', [
                'view' => ($this->showDecisions)(new ShowCardDecisionsCommand($card)),
                'offer' => $decision,
                'followUp' => $followUp,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
