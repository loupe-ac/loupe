<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\Fields\FieldSelection;
use App\Api\OpenApi\FieldsParameter;
use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\PageRequest;
use App\Controller\AppController;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Command\PageCardEventsCommand;
use App\Module\Board\Command\PageCardEventsHandler;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Mcp\CardEventPayload;
use App\Module\Board\Service\CardApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The history reads newest first, so the list takes no `sort`. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'listCardEvents',
    summary: 'Read the history of a card',
    tags: ['Cards'],
    description: 'The history reads newest first, so this list takes no `sort`. Each event names its actor. When `actor.kind` is `api-key`, `actor.apiKey` holds the id and the name of the key.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new FieldsParameter(CardEventResponse::FIELDS),
    ],
    responses: [
        new SuccessResponse(200, 'One page of events, newest first.', CardEventListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}/events',
    name: 'api_card_event_list',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['GET'],
)]
final class ListCardEventsController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly PageCardEventsHandler $pageEvents,
        private readonly CardEventPayload $payload,
        private readonly CardApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, int $number): JsonResponse
    {
        $fields = FieldSelection::fromRequest($request, CardEventResponse::FIELDS);
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();

        $view = ($this->pageEvents)(new PageCardEventsCommand($card, PageRequest::fromRequest($request, PageCardEventsHandler::NEWEST_FIRST)));

        return $view->page->toResponse($request, fn (CardEvent $event): array => $fields->keep($this->payload->forEvent($event)));
    }
}
