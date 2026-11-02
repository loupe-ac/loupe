<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Command\ShowCardCommand;
use App\Module\Board\Command\ShowCardHandler;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Service\CardApiProblems;
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
    operationId: 'getCard',
    summary: 'Read one card',
    tags: ['Cards'],
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new FieldsParameter(CardResponse::FIELDS),
    ],
    responses: [
        new SuccessResponse(200, 'The whole card.', CardResponse::class),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}',
    name: 'api_card_show',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['GET'],
)]
final class ShowPublicCardController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly ShowCardHandler $showCard,
        private readonly CardPayload $payload,
        private readonly CardApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, int $number): JsonResponse
    {
        $fields = FieldSelection::fromRequest($request, CardResponse::FIELDS);
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();

        return new JsonResponse($fields->keep($this->payload->forCard(($this->showCard)(new ShowCardCommand($card)))));
    }
}
