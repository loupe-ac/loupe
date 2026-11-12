<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Command\ShowProjectInboxItemCommand;
use App\Module\Inbox\Command\ShowProjectInboxItemHandler;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxCloser;
use App\Module\Inbox\Mcp\InboxItemPayload;
use App\Module\Inbox\Service\InboxApiProblems;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\Service\RunCommandConfirmation;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Security\CurrentApiKeyActor;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'runInboxItemAction',
    summary: 'Run an action of an inbox item',
    tags: ['Inbox'],
    description: 'Responds to an open item as the owner, or runs another built-in action of it. The item shows that an API key did it. `answer`, `complete` and `dismiss` are responses, and a response is final. `run-command` runs a command on the machine of the owner, so it needs two calls: read the command with `previewInboxItemCommand`, show it to the owner, then send the same `branch` and `params` with the `runDigest` of that preview. Without a `runDigest` it answers 422, and with a stale one it answers 409 `run_digest_stale`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'itemId', description: 'The item id, as `createInboxItem` returns it.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: RunInboxItemActionRequest::class))),
    responses: [
        new SuccessResponse(200, 'The action ran.', InboxActionResultResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'inbox_item_not_found', 'route_not_found']),
        new ProblemResponse(409, ['item_not_open', 'run_digest_stale']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items/{itemId}/actions',
    name: 'api_inbox_item_action',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class RunInboxItemActionController extends AppController
{
    public function __construct(
        private readonly ShowProjectInboxItemHandler $showItem,
        private readonly RunInboxActionHandler $run,
        private readonly RunCommandConfirmation $confirmation,
        private readonly InboxItemPayload $payload,
        private readonly InboxApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $itemId, #[MapRequestPayload] RunInboxItemActionRequest $payload): JsonResponse
    {
        $detail = ($this->showItem)(new ShowProjectInboxItemCommand($project, $itemId)) ?? throw $this->problems->itemNotFound();
        $item = $detail->item;
        $kind = InboxActionKind::from($payload->action ?? throw new \LogicException('action required after validation'));
        $branch = $payload->branch ?? null;
        $params = $payload->params ?? null;
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('The public API always authenticates a user.');
        }

        $runDigest = $payload->runDigest ?? null;
        if (InboxActionKind::RunCommand === $kind && (null === $runDigest || '' === $runDigest)) {
            throw ApiProblemException::validationFailed([['field' => 'runDigest', 'code' => 'run_digest_required', 'message' => 'Read the command with previewInboxItemCommand, then send its runDigest.']]);
        }

        $needsOpenItem = InboxActionKind::StartSession !== $kind;
        try {
            $handlerDigest = InboxActionKind::RunCommand === $kind ? $this->confirmation->confirm($item, $branch, $params, (string) $runDigest) : null;
            $recorded = ($this->run)(new RunInboxActionCommand(
                item: $item,
                kind: $kind,
                note: $payload->note ?? '',
                user: $user,
                branch: $branch,
                params: $params,
                runDigest: $handlerDigest,
                sessionPrompt: InboxActionKind::StartSession === $kind ? ($payload->sessionPrompt ?? ItemActions::suggestedSessionPrompt($item)) : null,
                closedBy: InboxCloser::OwnerThroughApiKey,
                apiKey: $this->currentKey->current(),
                selectedOptions: implode(',', $payload->selectedOptions ?? []),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->fromAction($e, $item, $needsOpenItem);
        }

        $after = ($this->showItem)(new ShowProjectInboxItemCommand($project, (string) $item->id)) ?? throw $this->problems->itemNotFound();

        return new JsonResponse([
            'actionId' => null === $recorded?->id ? null : (string) $recorded->id,
            'item' => $this->payload->forItem($after->item, $after->asks),
        ]);
    }
}
