<?php

declare(strict_types=1);

namespace App\Module\Readiness\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPost;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Readiness\Command\DiscoveryRunning;
use App\Module\Readiness\Command\StartDiscoveryCommand;
use App\Module\Readiness\Command\StartDiscoveryHandler;
use App\Module\Readiness\Service\DiscoveryApiProblems;
use App\Module\Workflow\Contract\Actor;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPost(
    operationId: 'startDiscovery',
    summary: 'Start a discovery run',
    tags: ['Discovery'],
    description: 'Loupe adds a tooling card to Backlog, and a bridge that serves the project runs a read-only worker on it. The worker checks the repository and the workflow, and writes a readiness report that lists what remains and who acts. The request takes no body. `url` is the page of the card, which links the report once the worker has written it. `Location` names the card. A live bridge must serve the project, the project must have a workflow with board automation on, and only one run can be open at a time.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'Idempotency-Key', description: 'A key of your choice. A retry with the same key and body answers as the first request did. The same key with another body gets 409 `idempotency_key_reused`.', in: 'header', schema: new OA\Schema(type: 'string', maxLength: 255)),
    ],
    responses: [
        new SuccessResponse(201, 'The new discovery run and its card.', DiscoveryStartResponse::class, location: true),
        new ProblemResponse(404, ['project_not_found', 'route_not_found']),
        new ProblemResponse(409, ['no_bridge', 'no_capable_bridge', 'bridge_paused', 'no_workflow', 'automation_off', 'discovery_running', 'idempotency_key_reused', 'idempotency_in_progress']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/discovery',
    name: 'api_discovery_start',
    methods: ['POST'],
)]
final class StartProjectDiscoveryController extends AppController
{
    public function __construct(
        private readonly StartDiscoveryHandler $startDiscovery,
        private readonly DiscoveryApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project): JsonResponse
    {
        try {
            $run = ($this->startDiscovery)(new StartDiscoveryCommand($project, $this->currentKey->actorKind(Actor::Human)));
        } catch (DomainErrors|DiscoveryRunning $e) {
            throw $this->problems->from($e);
        }

        $cardId = (string) $run->card->id;

        return new JsonResponse(
            new DiscoveryStartResponse((string) $run->id, $cardId, $run->card->number, $run->state->value, $this->links->project((string) $project->id)),
            JsonResponse::HTTP_CREATED,
            ['Location' => $this->generateUrl('api_card_show', ['project' => (string) $project->id, 'number' => $run->card->number], UrlGeneratorInterface::ABSOLUTE_URL)],
        );
    }
}
