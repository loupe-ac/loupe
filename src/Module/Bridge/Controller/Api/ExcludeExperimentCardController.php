<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPut;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ExcludeExperimentCardCommand;
use App\Module\Bridge\Command\ExcludeExperimentCardHandler;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
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
#[PublicPut(
    operationId: 'excludeExperimentCard',
    summary: 'Leave a card out of an experiment',
    tags: ['Experiments'],
    description: 'The comparison then drops the card from every figure, metric, control and headline of every variant, and lists it with the left-out reason `excluded`. A second call replaces the reason, the author and the time. The experiment must list the card, and a listed card with no number cannot be reached. `DELETE` on the same path puts the card back.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'experiment', description: 'The name of the experiment, as the runs name it.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'php-lsp-4'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: ExcludeExperimentCardRequest::class))),
    responses: [
        new SuccessResponse(200, 'The card, now excluded.', ExperimentCardRowResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'experiment_not_found', 'card_not_in_experiment', 'route_not_found']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments/{experiment}/cards/{number}/exclusion',
    name: 'api_experiment_card_exclude',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME, 'number' => ExperimentApiRoutes::CARD_NUMBER],
    methods: ['PUT'],
)]
final class ExcludeExperimentCardController extends AppController
{
    public function __construct(
        private readonly ExcludeExperimentCardHandler $exclude,
        private readonly ShowExperimentHandler $showExperiment,
        private readonly ExperimentCardPayload $payload,
        private readonly ExperimentApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $experiment, int $number, #[MapRequestPayload] ExcludeExperimentCardRequest $body): JsonResponse
    {
        try {
            $cardId = ($this->exclude)(new ExcludeExperimentCardCommand(
                project: $project,
                experiment: $experiment,
                number: $number,
                reason: trim($body->reason ?? '') ?: throw new \LogicException('reason required after validation'),
                user: $this->getUser() instanceof User ? $this->getUser() : null,
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        $view = ($this->showExperiment)(new ShowExperimentCommand(project: $project, experiment: $experiment, withMetrics: false, withPullRequestUrls: true, onlyCard: $cardId));
        $card = $view->cards[0] ?? throw new \LogicException('The experiment lists the card it just excluded.');

        return new JsonResponse($this->payload->forCard($card));
    }
}
