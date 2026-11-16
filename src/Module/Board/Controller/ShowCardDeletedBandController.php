<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The live read of a full card page after a delete signal or a reconnect: a
 * stream that shows the deleted band when the card is gone, and nothing when
 * it is there. The project decides access, because a deleted card has no voter.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/cards/{cardId}/deleted-band',
    name: 'app_board_card_deleted_band',
    requirements: ['projectId' => Requirement::UUID, 'cardId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowCardDeletedBandController extends AppController
{
    public function __invoke(
        #[MapEntity(id: 'projectId')] Project $project,
        #[MapEntity(expr: 'repository.findOneByIdAndProjectId(cardId, projectId)')] ?Card $card,
    ): Response {
        if (null !== $card) {
            return new Response('', Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
        }

        return new Response($this->renderView('@Board/_card_deleted_band.stream.html.twig', ['project' => $project]), Response::HTTP_OK, [
            'Content-Type' => TurboBundle::STREAM_MEDIA_TYPE,
        ]);
    }
}
