<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Api\PageLinks;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Service\CardApiProblems;
use App\Module\Bridge\Command\ReleaseCardAgentsCommand;
use App\Module\Bridge\Command\ReleaseCardAgentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Contract\Actor;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'releaseCard',
    summary: 'Manage a held card again',
    tags: ['Cards'],
    description: 'The queued runs on the card then start, and the workflow takes the card as it is now. A paused card stays paused, because this call does not lift a workflow pause. Use the pause route for that.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new SuccessResponse(200, 'The card is managed again.', CardReleaseResponse::class),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(409, ['not_paused']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}/hold',
    name: 'api_card_hold_release',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['DELETE'],
)]
final class ReleaseCardHoldController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly ReleaseCardAgentsHandler $release,
        private readonly CardApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
        private readonly PageLinks $links,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, int $number): JsonResponse
    {
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        try {
            ($this->release)(new ReleaseCardAgentsCommand($project, $cardId, $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.'), Actor::ApiKey === $this->currentKey->actorKind(Actor::Agent) ? 'api-key' : 'agent'));
        } catch (DomainErrors $e) {
            throw $this->problems->fromHold($e);
        }

        return new JsonResponse(new CardReleaseResponse((string) $cardId, $this->links->card((string) $project->id, (string) $cardId)));
    }
}
