<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Inbox\Command\ShowProjectInboxItemCommand;
use App\Module\Inbox\Command\ShowProjectInboxItemHandler;
use App\Module\Inbox\Service\InboxApiProblems;
use App\Module\Inbox\Service\InboxItemPayload;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'getInboxItem',
    summary: 'Read one inbox item and its answer',
    tags: ['Inbox'],
    description: 'Returns the item with the answer of the owner, the same fields as the `inbox_get` MCP tool. `selectedOptions` holds the indexes of the options the owner picked, and `note` holds the words of whoever closed the item. `apiKey` names the key that made an `api-key` item.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'itemId', description: 'The item id, as `createInboxItem` returns it.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The item.', InboxItemResponse::class),
        new ProblemResponse(404, ['project_not_found', 'inbox_item_not_found', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items/{itemId}',
    name: 'api_inbox_item_show',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['GET'],
)]
final class ShowInboxItemController extends AppController
{
    public function __construct(
        private readonly ShowProjectInboxItemHandler $showItem,
        private readonly InboxItemPayload $payload,
        private readonly InboxApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $itemId): JsonResponse
    {
        $detail = ($this->showItem)(new ShowProjectInboxItemCommand($project, $itemId)) ?? throw $this->problems->itemNotFound();

        return new JsonResponse($this->payload->forItem($detail->item, $detail->asks));
    }
}
