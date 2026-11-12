<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Review\Command\ListTagsCommand;
use App\Module\Review\Command\ListTagsHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listTags',
    summary: 'List the tags',
    tags: ['Tags'],
    description: 'The tag vocabulary of the project by name, with how many documents carry each tag. Tags are coined by use, so read this before you tag a batch and join the names in use instead of coining near-duplicates. A tag that no document carries any longer stays in the list with a count of zero.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of tags.', TagListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/tags',
    name: 'api_tag_list',
    methods: ['GET'],
)]
final class ListTagsController extends AppController
{
    private const string SORT = 'name';

    public function __construct(
        private readonly ListTagsHandler $listTags,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $page = PageRequest::fromRequest($request, self::SORT);

        $view = ($this->listTags)(new ListTagsCommand(project: $project, afterName: $page->cursor?->namePosition(), limit: $page->limit));

        $last = [] === $view->tags ? null : array_last($view->tags);
        $next = $view->hasMore && null !== $last ? Cursor::afterName(self::SORT, $last['tag']->name) : null;

        return new Page($view->tags, $next)->toResponse($request, static fn (array $row): array => ['name' => $row['tag']->name, 'documentCount' => $row['documentCount']]);
    }
}
