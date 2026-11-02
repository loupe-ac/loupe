<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicDelete;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Service\CardApiProblems;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseReleaseHandler;
use App\Security\CurrentApiKeyActor;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicDelete(
    operationId: 'releaseCardPause',
    summary: 'Lift the workflow pause of a card',
    tags: ['Cards'],
    description: 'The workflow pauses a card when a rule used up its retries or its work limit, or when a worker stopped with a refusal that the workflow does not retry. Lift a pause only when its cause is gone. A pause of kind `rule` ends only on its own release condition. This call differs from the hold route, which makes a card unmanaged.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'pauseId', description: 'Lift this pause only, so a pause that changed since you read it is refused. Leave it out to lift the active pause.', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new SuccessResponse(200, 'The pause ended.', CardPauseReleaseResponse::class),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(409, ['card_unmanaged', 'not_paused', 'pause_changed', 'kind_not_releasable']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}/pause',
    name: 'api_card_pause_release',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['DELETE'],
)]
final class ReleaseCardPauseController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly PauseReleaseHandler $release,
        private readonly CardApiProblems $problems,
        private readonly CurrentApiKeyActor $currentKey,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, int $number): JsonResponse
    {
        $pauseId = $request->query->all()['pauseId'] ?? null;
        if (null !== $pauseId && (!\is_string($pauseId) || !Uuid::isValid($pauseId))) {
            throw ApiProblemException::validationFailed([['field' => 'pauseId', 'code' => 'pause_id_invalid', 'message' => 'pauseId must be the pauseId of the pause that the card shows.']]);
        }
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();
        $user = $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.');

        try {
            $released = $this->release->release(
                $card->snapshot(),
                $user->id ?? throw new \LogicException('A signed-in user has an id.'),
                $this->currentKey->actorKind(Actor::Agent),
                null === $pauseId ? null : Uuid::fromString($pauseId),
            );
        } catch (DomainErrors $e) {
            throw $this->problems->fromPauseRelease($e);
        }

        return new JsonResponse(new CardPauseReleaseResponse((string) $card->id, 'released', $released->kind->value, $released->reason, $released->ruleId));
    }
}
