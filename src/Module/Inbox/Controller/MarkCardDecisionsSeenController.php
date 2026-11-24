<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Inbox\Command\MarkCardDecisionsSeenCommand;
use App\Module\Inbox\Command\MarkCardDecisionsSeenHandler;
use App\Module\Inbox\Service\InboxAvailability;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

/** Sent by the card page when a person opens the Decisions tab. */
#[CsrfToken('inbox-decisions-seen')]
#[IsGranted(CardVoter::VIEW, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/decisions/seen',
    name: 'app_inbox_card_decisions_seen',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class MarkCardDecisionsSeenController extends AppController
{
    public function __construct(
        private readonly MarkCardDecisionsSeenHandler $markSeen,
        private readonly InboxAvailability $inbox,
    ) {
    }

    public function __invoke(
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $this->inbox->requireEnabled();
        ($this->markSeen)(new MarkCardDecisionsSeenCommand($card, $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in user.')));

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
