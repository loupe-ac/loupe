<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\ShowProjectCardCommand;
use App\Module\Board\Command\ShowProjectCardHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The column a card is in now, and whether a person paused the agents on it.
 * The bridge reads it before it resumes a run, and skips the resume when the
 * card left that column. The firewall admits agent-scoped tokens alone.
 */
#[Route(
    '/api/v1/bridge/projects/{handle}/cards/{cardId}',
    name: 'api_project_board_card_show',
    requirements: ['handle' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['GET'],
)]
#[Route(
    '/api/projects/{handle}/board/cards/{cardId}',
    name: 'api_legacy_project_board_card_show',
    requirements: ['handle' => '[^/]+', 'cardId' => '[^/]+'],
    methods: ['GET'],
)]
final class ShowProjectCardController extends AppController
{
    public function __construct(
        private readonly ShowProjectCardHandler $showCard,
    ) {
    }

    public function __invoke(string $handle, string $cardId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Card endpoint reached without an authenticated User.');
        }

        $view = ($this->showCard)(new ShowProjectCardCommand($user, $handle, $cardId));
        if (null === $view->project) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'project_not_found', 'No project answers this request.');
        }

        if (null === $view->card) {
            throw ApiProblemException::refused(JsonResponse::HTTP_NOT_FOUND, 'card_not_found', 'No card answers this request.');
        }

        return $this->json([
            'cardId' => (string) $view->card->id,
            'number' => $view->card->number,
            'column' => $view->card->column->slug,
            'held' => $view->held,
        ]);
    }
}
