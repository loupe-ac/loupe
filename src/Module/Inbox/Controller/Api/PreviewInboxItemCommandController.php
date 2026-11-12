<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Inbox\Command\ShowProjectInboxItemCommand;
use App\Module\Inbox\Command\ShowProjectInboxItemHandler;
use App\Module\Inbox\Service\InboxApiProblems;
use App\Module\Inbox\Service\RunCommandConfirmation;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[PublicGet(
    operationId: 'previewInboxItemCommand',
    summary: 'Preview the command of an inbox item',
    tags: ['Inbox'],
    description: 'Shows the command that the `run-command` action of an item would run: the entry, the bridge, the branch, the argv and the params, with a `runDigest`. Nothing runs. Show the preview to the owner. Then call `runInboxItemAction` with the same `branch` and `params` and with the `runDigest`. The command runs only when the digest still matches the command the bridge reports. `branch` and `params` are optional, and the values of the suggestion apply when you leave them out.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'itemId', description: 'The item id, as `createInboxItem` returns it.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'branch', description: 'The branch to preview the command on.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'params', description: 'The value of each param, as `params[name]=value`.', in: 'query', schema: new OA\Schema(type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')), style: 'deepObject', explode: true),
    ],
    responses: [
        new SuccessResponse(200, 'The command, and the digest that confirms it.', InboxCommandPreviewResponse::class),
        new ProblemResponse(404, ['project_not_found', 'inbox_item_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/inbox/items/{itemId}/command',
    name: 'api_inbox_item_command',
    requirements: ['itemId' => Requirement::UUID],
    methods: ['GET'],
)]
final class PreviewInboxItemCommandController extends AppController
{
    public function __construct(
        private readonly ShowProjectInboxItemHandler $showItem,
        private readonly RunCommandConfirmation $confirmation,
        private readonly InboxApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $itemId): JsonResponse
    {
        $detail = ($this->showItem)(new ShowProjectInboxItemCommand($project, $itemId)) ?? throw $this->problems->itemNotFound();

        $all = $request->query->all();
        $rawBranch = $all['branch'] ?? null;
        $branch = null;
        $rawParams = $all['params'] ?? null;
        $params = null;
        $errors = [];
        if (null !== $rawBranch) {
            if (!\is_string($rawBranch) || '' === $rawBranch) {
                $errors[] = ['field' => 'branch', 'code' => 'branch_invalid', 'message' => 'branch must be a non-empty text.'];
            } else {
                $branch = $rawBranch;
            }
        }
        if (null !== $rawParams) {
            $params = [];
            foreach (\is_array($rawParams) ? $rawParams : [null] as $name => $value) {
                if (!\is_string($value)) {
                    $errors[] = ['field' => 'params', 'code' => 'params_invalid', 'message' => 'params must map each param name to a string, as params[name]=value.'];
                    $params = null;
                    break;
                }
                $params[(string) $name] = $value;
            }
        }
        if ([] !== $errors) {
            throw ApiProblemException::validationFailed($errors);
        }

        try {
            [$view, $digest] = $this->confirmation->preview($detail->item, $branch, $params);
        } catch (DomainErrors $e) {
            throw $this->problems->from($e);
        }

        return new JsonResponse([
            'itemId' => (string) $detail->item->id,
            'entry' => $view->entry,
            'bridge' => $view->bridgeName,
            'branch' => $view->branch,
            'runList' => $view->runList,
            'argv' => $view->argv,
            'params' => (object) $view->values(),
            'runDigest' => $digest,
            'disabledReason' => null === $view->disabledReasonKey ? null : $this->problems->sentence($view->disabledReasonKey),
        ]);
    }
}
