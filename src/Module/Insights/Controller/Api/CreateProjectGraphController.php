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
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Service\InsightsApiProblems;
use App\Module\Insights\Service\SavedGraphChangeRefused;
use App\Module\Insights\Service\SavedGraphSettingsMerger;
use App\Module\Insights\Service\SavedGraphSettingsProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createGraph',
    summary: 'Save a graph as a new tile at the end of the Dashboard',
    tags: ['Graphs'],
    description: 'A saved graph is a tile on the Dashboard of the owner. A graph reads one metric (`settings.metric` and `settings.statistic`) or a formula (`rows`, `formula` and `formulaFormat`), and not both. `settings.unit` is needed. A setting that the metric or the range cannot take is refused with the values that are valid, and nothing is saved. A new graph takes half the width and shows its legend, and a change alters both.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateGraphRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new graph.', GraphResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['dashboard_full', 'idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/graphs',
    name: 'api_graph_create',
    methods: ['POST'],
)]
final class CreateProjectGraphController extends AppController
{
    public function __construct(
        private readonly SaveSavedGraphHandler $saveGraph,
        private readonly DescribeSavedGraphHandler $describeGraph,
        private readonly SavedGraphSettingsProblems $settingsProblems,
        private readonly InsightsApiProblems $problems,
    ) {
    }

    public function __invoke(
        #[ReachableProject] Project $project,
        #[MapRequestPayload] CreateGraphRequest $payload,
    ): JsonResponse {
        $settings = $payload->settingsMap();
        try {
            SavedGraphSettingsMerger::checkNew($settings);
        } catch (SavedGraphChangeRefused $e) {
            throw $this->problems->changeRefused($e);
        }
        $invalid = $this->settingsProblems->find($project, $settings);
        if ([] !== $invalid) {
            throw $this->problems->invalidSettings($invalid);
        }

        try {
            $saved = ($this->saveGraph)(new SaveSavedGraphCommand($project, $payload->name, $settings));
        } catch (DomainErrors $e) {
            throw $this->problems->forGraph($e);
        }

        return new JsonResponse(
            GraphResponse::fromRow(($this->describeGraph)(new DescribeSavedGraphCommand($saved->graph))),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_graph_show', ['project' => (string) $project->id, 'graph' => (string) $saved->graph->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
