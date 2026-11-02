<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Board\Command\PageCardsCommand;
use App\Module\Board\Command\PageCardsHandler;
use App\Module\Board\Mcp\CardPayload;
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
    operationId: 'listCards',
    summary: 'List or search the cards',
    tags: ['Cards'],
    description: 'Cards read in creation order, oldest first, so a walk never skips a card that moves between columns. `q` narrows the walk and does not rank it. Terminal columns are included.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'sort', description: 'The walk order. A leading `-` is descending. `createdAt` is the same order as `number`, because a number counts up in creation order.', in: 'query', schema: new OA\Schema(type: 'string', default: 'number', enum: ['number', '-number', 'createdAt', '-createdAt', 'updatedAt', '-updatedAt'])),
        new OA\Parameter(name: 'q', description: 'Search words, in the title and the body of every card. Matching is by substring, ignoring case. Every word must match. Quote a phrase, prefix `-` to exclude, and write `or` for either. `12` or `#12` also finds that card.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', description: 'Only cards in the column with this slug.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'type', description: 'Only cards of this type key.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'reporter', description: 'Only cards raised by this reporter.', in: 'query', schema: new OA\Schema(type: 'string', enum: ['human', 'agent', 'reviewer', 'api-key', 'system'])),
        new OA\Parameter(name: 'parentCardId', description: 'Only the children of the card with this id.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'parent', description: 'Only the children of the card with this number. Do not pass it with `parentCardId`.', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'paused', description: 'True for only the cards with an active pause, and false for only those with none.', in: 'query', schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'full', description: 'Return the whole card rather than the summary, which is far larger.', in: 'query', schema: new OA\Schema(type: 'boolean', default: false)),
    ],
    responses: [
        new SuccessResponse(200, 'One page of cards.', CardListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards',
    name: 'api_card_list',
    methods: ['GET'],
)]
final class ListProjectCardsController extends AppController
{
    public function __construct(
        private readonly PageCardsHandler $pageCards,
        private readonly CardPayload $payload,
        private readonly PublicCardAnswer $answer,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $query = CardListQuery::fromRequest($request);
        $filters = $query->filters();

        $view = ($this->pageCards)(new PageCardsCommand(
            project: $project,
            page: PageRequest::fromRequest($request, $query->sort->value, $filters),
            sort: $query->sort,
            filters: $filters,
            query: $query->query,
            status: $query->status,
            type: $query->type,
            reporter: $query->reporter,
            parentCardId: $query->parentCardId,
            parentNumber: $query->parentNumber,
            paused: $query->paused,
        ));

        // CardPayload reads the links of the whole page in a few queries, so it runs before the page is cut into rows.
        $cards = $view->page->items;
        $projectId = (string) $project->id;
        $rows = new Page(
            $query->full
                ? array_map(fn (array $card): array => $this->answer->card($card, $projectId), $this->payload->forCards($cards))
                : array_map(fn (array $row): array => $this->answer->row($row, $projectId), $this->payload->forCardList($cards)),
            $view->page->next,
        );

        return $rows->toResponse($request, static fn (array $row): array => $row);
    }
}
