<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPatch;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\ArchiveExperimentCommand;
use App\Module\Bridge\Command\ArchiveExperimentHandler;
use App\Module\Bridge\Command\ListExperimentsCommand;
use App\Module\Bridge\Command\ListExperimentsHandler;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPatch(
    operationId: 'updateExperiment',
    summary: 'Archive or restore an experiment',
    tags: ['Experiments'],
    description: 'Archiving is curation: the experiment leaves the main list and shows under `archived=true`, and its data stays readable. Pins and variant assignment work as before. Archiving an archived experiment moves its archive time forward, and restoring one that is not archived changes nothing.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'experiment', description: 'The name of the experiment, as the runs name it.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'php-lsp-4'),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: UpdateExperimentRequest::class))),
    responses: [
        new SuccessResponse(200, 'The experiment after the change.', ExperimentSummaryRowResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'experiment_not_found', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments/{experiment}',
    name: 'api_experiment_update',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['PATCH'],
)]
final class UpdateExperimentController extends AppController
{
    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
        private readonly ArchiveExperimentHandler $archive,
        private readonly UnarchiveExperimentHandler $unarchive,
        private readonly ListExperimentsHandler $listExperiments,
        private readonly ExperimentReportPayload $payload,
        private readonly ExperimentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $experiment, #[MapRequestPayload] UpdateExperimentRequest $body): JsonResponse
    {
        // The unarchive handler ignores a name that nothing uses, so the route checks it for both directions.
        if (null === ($this->showExperiment)(new ShowExperimentCommand(project: $project, experiment: $experiment, withMetrics: false, limit: 1))) {
            throw $this->problems->experimentNotFound();
        }

        try {
            if ($body->archived ?? throw new \LogicException('archived required after validation')) {
                ($this->archive)(new ArchiveExperimentCommand($project, $experiment));
            } else {
                ($this->unarchive)(new UnarchiveExperimentCommand($project, $experiment));
            }
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        foreach ([true, false] as $archived) {
            foreach (($this->listExperiments)(new ListExperimentsCommand(project: $project, archived: $archived))->experiments as $summary) {
                if ($summary->name === $experiment) {
                    return new JsonResponse($this->payload->forSummary($summary));
                }
            }
        }

        throw new \LogicException('The experiment appears in the archived list or in the other list.');
    }
}
