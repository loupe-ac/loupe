<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Insights\Command\DescribeSavedGraphCommand;
use App\Module\Insights\Command\DescribeSavedGraphHandler;
use App\Module\Insights\Command\SavedGraphSettingsChange;
use App\Module\Insights\Command\UpdateSavedGraphFieldsCommand;
use App\Module\Insights\Command\UpdateSavedGraphFieldsHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Insights\Service\SavedGraphChangeRefused;
use App\Module\Insights\Service\SavedGraphLookup;
use App\Module\Insights\Service\SavedGraphSettingsInvalid;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateGraph',
    summary: 'Change a saved graph',
    tags: ['Graphs'],
    description: 'Every change commits in one transaction, so a refusal leaves the graph as it was. The settings you pass join the settings the graph keeps, and the whole set must hold together: a setting that the metric or the range cannot take is refused with the values that are valid, also one that the graph kept. A formula graph keeps its first row as `settings.metric` and `settings.statistic`, so a bare metric or statistic on a formula graph is refused: pass `rows` to change the rows, or `clearFormula` to go back to one metric. A removed graph answers 404.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'graph', description: 'The id of a saved graph, from the graph list.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateGraphRequest::class))),
    responses: [
        new SuccessResponse(200, 'The graph after the change.', GraphResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'graph_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs/{graph}',
    name: 'api_graph_update',
    requirements: ['graph' => Requirement::UUID],
    methods: ['PATCH'],
)]
final class UpdateProjectGraphController extends AppController
{
    public function __construct(
        private readonly SavedGraphLookup $lookup,
        private readonly UpdateSavedGraphFieldsHandler $updateGraph,
        private readonly DescribeSavedGraphHandler $describeGraph,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $graph, #[MapRequestPayload] UpdateGraphRequest $payload): JsonResponse
    {
        $found = $this->lookup->find($project, $graph);
        if (null === $found || null !== $found->removedAt) {
            throw $this->problems->graphNotFound();
        }

        $change = new SavedGraphSettingsChange($payload->passed(), $payload->formulaPassed(), $payload->clearFormula ?? false);
        $command = new UpdateSavedGraphFieldsCommand(
            $project,
            $graph,
            name: $payload->name ?? null,
            change: $change,
            width: $payload->width ?? null,
            showLegend: $payload->showLegend ?? null,
            position: $payload->position ?? null,
        );
        try {
            ($this->updateGraph)($command);
        } catch (SavedGraphChangeRefused $e) {
            throw $this->problems->changeRefused($e);
        } catch (SavedGraphSettingsInvalid $e) {
            throw $this->problems->invalidSettings($e->problems);
        } catch (DomainErrors $e) {
            throw $this->problems->forGraph($e);
        } catch (NotFoundHttpException) {
            throw $this->problems->graphNotFound();
        }

        return new JsonResponse(GraphResponse::fromRow(($this->describeGraph)(new DescribeSavedGraphCommand($found))));
    }
}
