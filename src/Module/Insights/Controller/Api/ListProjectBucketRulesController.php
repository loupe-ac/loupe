<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Insights\Command\BucketRuleRow;
use App\Module\Insights\Command\PageBucketRulesCommand;
use App\Module\Insights\Command\PageBucketRulesHandler;
use App\Module\Insights\Service\PositionCursor;
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
    operationId: 'listBucketRules',
    summary: 'List the time bucket rules of a project',
    tags: ['Bucket rules'],
    description: 'The rules come in the order they apply. A tool call takes the bucket of the first rule whose pattern matches it. A project holds at most 50 rules.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of bucket rules.', BucketRuleListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/bucket-rules',
    name: 'api_bucket_rule_list',
    methods: ['GET'],
)]
final class ListProjectBucketRulesController extends AppController
{
    public function __construct(
        private readonly PageBucketRulesHandler $pageRules,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $page = ($this->pageRules)(new PageBucketRulesCommand($project, PageRequest::fromRequest($request, PositionCursor::SORT)));

        return $page->toResponse($request, static fn (BucketRuleRow $row): BucketRuleResponse => BucketRuleResponse::fromRow($row));
    }
}
