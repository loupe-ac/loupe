<?php

declare(strict_types=1);

namespace App\Module\Board\Controller;

use App\Controller\AppController;
use App\Module\Board\Command\ShowCardPlacementsCommand;
use App\Module\Board\Command\ShowCardPlacementsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Several cards as the board page shows them, for a page that heard they changed.
 *
 * The gate is the board's own gate on the project rather than CardVoter,
 * because a deleted card has no subject to vote on and still owes the page a
 * removal. The card lookup is scoped to the project, so the two agree.
 */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{projectId}/board/placements',
    name: 'app_board_card_placements',
    requirements: ['projectId' => Requirement::UUID],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
final class ShowCardPlacementsController extends AppController
{
    private const int MAX_CARDS = 50;

    public function __construct(
        private readonly ShowCardPlacementsHandler $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')] Project $project,
    ): Response {
        $ids = [];
        foreach (explode(',', $request->query->getString('cards')) as $id) {
            if (!Uuid::isValid($id)) {
                throw new BadRequestHttpException('A card id is not a UUID.');
            }
            $ids[strtolower($id)] = true;
        }
        $ids = array_map(strval(...), array_keys($ids));
        if (\count($ids) > self::MAX_CARDS) {
            throw new BadRequestHttpException(\sprintf('A request places at most %d cards.', self::MAX_CARDS));
        }

        $view = ($this->handler)(new ShowCardPlacementsCommand($project, $ids));

        $streams = '';
        foreach ($view->placements as $cardId => $placement) {
            try {
                $streams .= $this->renderView('@Board/_card_placement.stream.html.twig', ['cardId' => $cardId, 'placement' => $placement]);
            } catch (\Throwable $exception) {
                $this->logger->error('board.placement_render_failed', ['cardId' => $cardId, 'projectId' => (string) $project->id, 'exception' => $exception]);
            }
        }

        return new Response($streams, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
