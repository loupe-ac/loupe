<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\ListInboxActionsCommand;
use App\Module\Inbox\Command\ListInboxActionsHandler;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Service\InboxLinkResolver;
use App\Module\Inbox\Service\SuggestibleInboxActions;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listInboxActions',
    summary: 'List the actions a worker can suggest',
    tags: ['Inbox'],
    description: 'The eight built-in actions that an agent can suggest on an item it asks, each with one line on its effect. The list is fixed and has no cursor. With `card`, `fitsCard` tells for each action whether it fits that card now, and it is null otherwise. A card with no open wait item fits `open`, `start-session` while a bridge can open a session and no session request of the card is live, and `send-agent` while its column names stage work that no request or worker does yet.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'card', description: 'The id of a card of this project, to learn which actions fit it now.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The actions.', InboxActionListResponse::class),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/actions',
    name: 'api_inbox_action_list',
    methods: ['GET'],
)]
final class ListInboxActionsController extends AppController
{
    public function __construct(
        private readonly ListInboxActionsHandler $listActions,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $card = $request->query->all()['card'] ?? null;
        if (null !== $card && (!\is_string($card) || !Uuid::isValid($card))) {
            throw ApiProblemException::validationFailed([['field' => 'card', 'code' => 'card_invalid', 'message' => 'card must be the id of a card.']]);
        }

        try {
            $fitting = ($this->listActions)(new ListInboxActionsCommand($project, null === $card ? null : Uuid::fromString($card)));
        } catch (DomainErrors $e) {
            if (InboxLinkResolver::CARD_UNKNOWN === ($e->errors['cardId'] ?? null)) {
                throw ApiProblemException::validationFailed([['field' => 'card', 'code' => 'card_unknown', 'message' => 'The card id must name a card of this project.']]);
            }

            throw $e;
        }

        return new JsonResponse(['actions' => array_map(
            static fn (string $action, string $effect): array => [
                'action' => $action,
                'effect' => $effect,
                'fitsCard' => null === $fitting ? null : \in_array(InboxActionKind::from($action), $fitting, true),
            ],
            array_keys(SuggestibleInboxActions::EFFECTS),
            SuggestibleInboxActions::EFFECTS,
        )]);
    }
}
