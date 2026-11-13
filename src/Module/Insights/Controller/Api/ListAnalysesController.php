<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Insights\Command\AnalysisDetailView;
use App\Module\Insights\Command\PageAnalysesCommand;
use App\Module\Insights\Command\PageAnalysesHandler;
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
    operationId: 'listAnalyses',
    summary: 'List the analyses of a project',
    tags: ['Analytics'],
    description: 'The analyses of the project, newest first, each with its cost and its proposals.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of analyses.', AnalysisListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/analyses',
    name: 'api_analysis_list',
    methods: ['GET'],
)]
final class ListAnalysesController extends AppController
{
    private const string SORT = 'createdAt';

    public function __construct(
        private readonly PageAnalysesHandler $pageAnalyses,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project): JsonResponse
    {
        $page = PageRequest::fromRequest($request, self::SORT);
        $after = $page->cursor?->createdPosition();

        $rows = ($this->pageAnalyses)(new PageAnalysesCommand($project, $page->fetchSize(), $after[0] ?? null, $after[1] ?? null));

        return Page::fromRows(
            $rows,
            $page,
            static fn (AnalysisDetailView $view): Cursor => Cursor::afterCreated(self::SORT, $view->analysis->createdAt, $view->analysis->id ?? throw new \LogicException('A stored analysis has an id.')),
        )->toResponse($request, static fn (AnalysisDetailView $view): AnalysisResponse => AnalysisResponse::fromView($view));
    }
}
