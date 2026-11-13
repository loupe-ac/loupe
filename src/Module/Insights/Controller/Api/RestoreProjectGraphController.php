<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\DescribeSavedGraphCommand;
use App\Module\Insights\Command\DescribeSavedGraphHandler;
use App\Module\Insights\Command\RestoreSavedGraphCommand;
use App\Module\Insights\Command\RestoreSavedGraphHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Insights\Service\SavedGraphLookup;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'restoreGraph',
    summary: 'Bring a removed graph back to its place on the Dashboard',
    tags: ['Graphs'],
    description: 'A graph can come back for one day after its removal. A graph that is already on the Dashboard stays as it is.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'graph', description: 'The id of a saved graph, from the graph list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The graph on the Dashboard.', GraphResponse::class),
        new ProblemResponse(404, ['project_not_found', 'graph_not_found', 'route_not_found']),
        new ProblemResponse(409, ['restore_expired', 'dashboard_full']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs/{graph}/restore',
    name: 'api_graph_restore',
    requirements: ['graph' => Requirement::UUID],
    methods: ['POST'],
)]
final class RestoreProjectGraphController extends AppController
{
    public function __construct(
        private readonly SavedGraphLookup $lookup,
        private readonly RestoreSavedGraphHandler $restoreGraph,
        private readonly DescribeSavedGraphHandler $describeGraph,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $graph): JsonResponse
    {
        $found = $this->lookup->findStored($project, $graph) ?? throw $this->problems->graphNotFound();

        try {
            ($this->restoreGraph)(new RestoreSavedGraphCommand($found));
        } catch (DomainErrors $e) {
            throw $this->problems->forGraph($e);
        } catch (NotFoundHttpException) {
            throw $this->problems->restoreExpired();
        }

        return new JsonResponse(GraphResponse::fromRow(($this->describeGraph)(new DescribeSavedGraphCommand($found))));
    }
}
