<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\AskInboxItem;
use App\Module\Inbox\Command\CreateInboxItemCommand;
use App\Module\Inbox\Command\CreateInboxItemHandler;
use App\Module\Inbox\Command\ShowInboxItemCommand;
use App\Module\Inbox\Command\ShowInboxItemHandler;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Mcp\InboxItemPayload;
use App\Module\Inbox\Service\InboxApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Security\CurrentApiKeyActor;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'createInboxItem',
    summary: 'Put an item in the inbox of the owner',
    tags: ['Inbox'],
    description: 'Hands one question, to-do or notice to the owner of the project, who answers it in the Loupe inbox. Nothing waits on the item, so `blocking` is always false, and the owner can dismiss any item. With `dedupeKey`, a call returns the open item that holds the same key with 200 and creates nothing. `Location` names the item. Read the answer with `getInboxItem`. The item names the API key that made it.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: CreateInboxItemRequest::class))),
    responses: [
        new SuccessResponse(201, 'The new item.', InboxItemResponse::class, location: true),
        new SuccessResponse(200, 'The open item that already holds the `dedupeKey`. Nothing was created.', InboxItemResponse::class, location: true),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['idempotency_key_reused', 'idempotency_in_progress']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items',
    name: 'api_inbox_item_create',
    methods: ['POST'],
)]
final class CreateInboxItemController extends AppController
{
    public function __construct(
        private readonly CreateInboxItemHandler $createItem,
        private readonly ShowInboxItemHandler $showItem,
        private readonly InboxItemPayload $payload,
        private readonly InboxApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, #[MapRequestPayload] CreateInboxItemRequest $payload): JsonResponse
    {
        try {
            $view = ($this->createItem)(new CreateInboxItemCommand(
                project: $project,
                item: new AskInboxItem(
                    kind: InboxItemKind::from($payload->kind ?? throw new \LogicException('kind required after validation')),
                    title: $payload->title ?? throw new \LogicException('title required after validation'),
                    body: $payload->body ?? null,
                    options: $payload->options ?? [],
                    multiple: $payload->multiple ?? false,
                    freeText: $payload->freeText ?? false,
                    blocking: false,
                    cardIds: $payload->cardIds ?? [],
                    documentIds: $payload->documentIds ?? [],
                ),
                apiKey: $this->currentKey->current(),
                dedupeKey: $payload->dedupeKey(),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        $detail = ($this->showItem)(new ShowInboxItemCommand($view->item));

        return new JsonResponse(
            $this->payload->forItem($detail->item, $detail->asks),
            $view->created ? JsonResponse::HTTP_CREATED : JsonResponse::HTTP_OK,
            ['Location' => $this->generateUrl('api_inbox_item_show', ['project' => (string) $project->id, 'itemId' => (string) $view->item->id], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
