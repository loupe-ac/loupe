<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Inbox\Command\PageInboxItemsCommand;
use App\Module\Inbox\Command\PageInboxItemsHandler;
use App\Module\Inbox\Mcp\InboxItemPayload;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listInboxItems',
    summary: 'List the inbox items',
    tags: ['Inbox'],
    description: 'The items of the project, newest number first, closed items included. Each row is the summary that `inbox_list` returns, so read one item with `getInboxItem` for its body and its answer. `q` narrows the walk to the items it matches, with the matching of `inbox_search`, and keeps the order of the numbers, so the best match does not come first. An id from another project matches nothing.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'state', description: 'Only items in this state.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'resolved', 'declined', 'cancelled'])),
        new OA\Parameter(name: 'card', description: 'Only the items linked to the card with this id.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'document', description: 'Only the items linked to the document with this id.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'ask', description: 'Only the items of the ask with this id.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'session', description: 'Only the items of the asks that the agent session with this id made.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'q', description: 'Search words, in the title, the body and the options of every item. Every word must match.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of inbox items.', InboxItemListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items',
    name: 'api_inbox_item_list',
    methods: ['GET'],
)]
final class ListInboxItemsController extends AppController
{
    public function __construct(
        private readonly PageInboxItemsHandler $pageItems,
        private readonly InboxItemPayload $payload,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $query = InboxItemListQuery::fromRequest($request);
        $filters = $query->filters();

        $view = ($this->pageItems)(new PageInboxItemsCommand(
            project: $project,
            page: PageRequest::fromRequest($request, PageInboxItemsHandler::SORT, $filters),
            filters: $filters,
            state: $query->state,
            askId: $query->askId,
            sessionId: $query->sessionId,
            cardId: $query->cardId,
            documentId: $query->documentId,
            query: $query->query,
        ));

        $rows = new Page($this->payload->forList($view->page->items), $view->page->next);

        return $rows->toResponse($request, static fn (array $row): array => $row);
    }
}
