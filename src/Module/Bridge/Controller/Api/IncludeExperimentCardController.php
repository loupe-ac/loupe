<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Bridge\Command\IncludeExperimentCardCommand;
use App\Module\Bridge\Command\IncludeExperimentCardHandler;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'includeExperimentCard',
    summary: 'Put a card back into an experiment',
    tags: ['Experiments'],
    description: 'Clears the manual exclusion of the card. The card counts again, unless an automatic left-out reason still applies to it. A card with no exclusion is left as it is, and the call still succeeds.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'experiment', description: 'The name of the experiment, as the runs name it.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'php-lsp-4'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new SuccessResponse(200, 'The card, with no exclusion.', ExperimentCardRowResponse::class),
        new ProblemResponse(404, ['project_not_found', 'experiment_not_found', 'card_not_in_experiment', 'route_not_found']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments/{experiment}/cards/{number}/exclusion',
    name: 'api_experiment_card_include',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME, 'number' => ExperimentApiRoutes::CARD_NUMBER],
    methods: ['DELETE'],
)]
final class IncludeExperimentCardController extends AppController
{
    public function __construct(
        private readonly IncludeExperimentCardHandler $include,
        private readonly ShowExperimentHandler $showExperiment,
        private readonly ExperimentCardPayload $payload,
        private readonly ExperimentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $experiment, int $number): JsonResponse
    {
        try {
            $cardId = ($this->include)(new IncludeExperimentCardCommand($project, $experiment, $number));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        $view = ($this->showExperiment)(new ShowExperimentCommand(project: $project, experiment: $experiment, withMetrics: false, withPullRequestUrls: true, onlyCard: $cardId));
        $card = $view->cards[0] ?? throw new \LogicException('The experiment lists the card it just included.');

        return new JsonResponse($this->payload->forCard($card));
    }
}
