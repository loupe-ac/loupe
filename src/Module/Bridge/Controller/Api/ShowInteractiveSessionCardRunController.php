<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ShowInteractiveSessionCardRunCommand;
use App\Module\Bridge\Command\ShowInteractiveSessionCardRunHandler;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the session start hook of the plugin which workflow template a project
 * follows and whether the session already holds an open run on a card. The
 * firewall admits agent-scoped tokens alone. The project comes from the
 * X-Loupe-Project header and never from a fallback, because the hook must not
 * guess a project. A project the token owner does not own answers like a
 * missing one. It shares the agent_worker_runs rate limit, which
 * RateLimitWorkerRunReports applies by route name.
 */
#[Route(
    '/api/interactive-sessions/{sessionId}/card-run',
    name: 'api_interactive_session_card_run',
    methods: ['GET'],
)]
final class ShowInteractiveSessionCardRunController extends AppController
{
    public function __construct(
        private readonly ShowInteractiveSessionCardRunHandler $showCardRun,
    ) {
    }

    public function __invoke(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Interactive session card run reached without an authenticated User.');
        }

        $handle = trim((string) $request->headers->get(AuthenticatedProjectResolver::PROJECT_HEADER, ''));
        if ('' === $handle) {
            return $this->json(['error' => 'project_header_required'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!Uuid::isValid($sessionId)) {
            return $this->json(['error' => 'invalid_session_id'], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $view = ($this->showCardRun)(new ShowInteractiveSessionCardRunCommand($user, $handle, Uuid::fromString($sessionId)));
        if (null === $view) {
            return $this->json(['error' => 'unknown_project'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['template' => $view->templateKey, 'openCardRun' => $view->openCardRun]);
    }
}
