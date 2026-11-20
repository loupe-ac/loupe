<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\ShowCardPlacementCommand;
use App\Module\Board\Command\ShowCardPlacementHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Security\CardVoter;
use App\Module\Workflow\Contract\Actor;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;
use Ubermuda\SymfonyExtra\Csrf\Attribute\CsrfToken;

#[CsrfToken('board-card-delete')]
#[IsGranted(CardVoter::WRITE, subject: 'card')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/delete',
    name: 'app_board_card_delete',
    requirements: ['cardId' => Requirement::UUID],
    methods: ['POST'],
)]
final class DeleteCardController extends AppController
{
    public function __construct(
        private readonly DeleteCardHandler $deleteCard,
        private readonly TranslatorInterface $translator,
        private readonly ShowCardPlacementHandler $showPlacement,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] Card $card,
    ): Response {
        $projectId = (string) $card->project->id;
        $cardId = (string) $card->id;
        $title = $card->title;

        try {
            ($this->deleteCard)(new DeleteCardCommand($card, Actor::Human));
        } catch (DomainErrors $e) {
            $this->addFlash('error', $this->translator->trans(array_first($e->errors)));

            return $this->redirectToRoute('app_board_card', ['projectId' => $projectId, 'cardId' => $cardId]);
        }

        $message = $this->translator->trans('board.card.flash.deleted', ['%title%' => $title]);

        if ('card-drawer-frame' === $request->headers->get('Turbo-Frame') && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            return new Response(
                $this->renderView('@Board/_card_placement.stream.html.twig', [
                    'cardId' => $cardId,
                    'placement' => ($this->showPlacement)(new ShowCardPlacementCommand($card->project, null)),
                ]).$this->renderView('_flash.stream.html.twig', ['severity' => 'success', 'message' => $message]),
                Response::HTTP_OK,
                ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
            );
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_project_board', ['id' => $projectId]);
    }
}
