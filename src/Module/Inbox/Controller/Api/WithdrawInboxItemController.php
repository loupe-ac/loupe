<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\ShowProjectInboxItemCommand;
use App\Module\Inbox\Command\ShowProjectInboxItemHandler;
use App\Module\Inbox\Command\WithdrawInboxItemCommand;
use App\Module\Inbox\Command\WithdrawInboxItemHandler;
use App\Module\Inbox\Service\InboxApiProblems;
use App\Module\Inbox\Service\InboxItemPayload;
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
    operationId: 'withdrawInboxItem',
    summary: 'Withdraw an open inbox item',
    tags: ['Inbox'],
    description: 'Takes back an open question, to-do or review that nobody needs answered any longer. The item closes as `cancelled` with the cause `withdrawn`, closed by `owner-through-api-key`, and the reason becomes its note. Any open item that an agent or a key asked can be withdrawn, also an ask that a worker waits on. An item that Loupe or a workflow rule opened, such as a wait, a notice or a workflow question, cannot.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'itemId', description: 'The item id, as `createInboxItem` returns it.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: new Model(type: WithdrawInboxItemRequest::class))),
    responses: [
        new SuccessResponse(200, 'The item after the withdrawal.', InboxItemResponse::class),
        new ProblemResponse(400, ['invalid_json']),
        new ProblemResponse(404, ['project_not_found', 'inbox_item_not_found', 'route_not_found']),
        new ProblemResponse(409, ['item_not_open', 'item_not_withdrawable']),
        new ProblemResponse(415, ['unsupported_media_type']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items/{itemId}/withdrawal',
    name: 'api_inbox_item_withdraw',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['POST'],
)]
final class WithdrawInboxItemController extends AppController
{
    public function __construct(
        private readonly ShowProjectInboxItemHandler $showItem,
        private readonly WithdrawInboxItemHandler $withdraw,
        private readonly InboxItemPayload $payload,
        private readonly InboxApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, string $itemId, #[MapRequestPayload] WithdrawInboxItemRequest $payload): JsonResponse
    {
        $detail = ($this->showItem)(new ShowProjectInboxItemCommand($project, $itemId)) ?? throw $this->problems->itemNotFound();

        try {
            $item = ($this->withdraw)(new WithdrawInboxItemCommand(
                item: $detail->item,
                reason: $payload->reason ?? throw new \LogicException('reason required after validation'),
                sessionId: null,
                apiKey: $this->currentKey->current(),
            ));
        } catch (DomainErrors $e) {
            throw $this->problems->fromWithdrawal($e);
        }

        $after = ($this->showItem)(new ShowProjectInboxItemCommand($project, (string) $item->id)) ?? throw $this->problems->itemNotFound();

        return new JsonResponse($this->payload->forItem($after->item, $after->asks));
    }
}
