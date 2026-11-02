<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\CloseInteractiveSessionCommand;
use App\Module\Bridge\Command\CloseInteractiveSessionHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Closes the open interactive runs of one session, as the exit hook of the
 * plugin asks. The firewall admits agent-scoped tokens alone. The route names
 * no project, because one session can work in several, and it carries no
 * feature flag, because a close needs no push and a session started by hand
 * needs no bridge.
 */
#[Route(
    '/api/v1/bridge/interactive-sessions/{sessionId}/close',
    name: 'api_interactive_session_close',
    methods: ['POST'],
)]
#[Route(
    '/api/interactive-sessions/{sessionId}/close',
    name: 'api_legacy_interactive_session_close',
    methods: ['POST'],
)]
final class CloseInteractiveSessionController extends AppController
{
    public function __construct(
        private readonly CloseInteractiveSessionHandler $closeInteractiveSession,
    ) {
    }

    public function __invoke(string $sessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive session close reached without an authenticated User.');
        }

        if (!Uuid::isValid($sessionId)) {
            throw ApiProblemException::refused(JsonResponse::HTTP_UNPROCESSABLE_ENTITY, 'invalid_session_id', 'The session id is not a valid id.');
        }

        $closed = ($this->closeInteractiveSession)(new CloseInteractiveSessionCommand($user, Uuid::fromString($sessionId)));

        return new JsonResponse(['closed' => $closed]);
    }
}
