<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicPut;
use App\Api\OpenApi\SuccessResponse;
use App\Controller\AppController;
use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Board\Command\FindCardByNumberCommand;
use App\Module\Board\Command\FindCardByNumberHandler;
use App\Module\Board\Service\CardApiProblems;
use App\Module\Bridge\Command\PauseCardAgentsCommand;
use App\Module\Bridge\Command\PauseCardAgentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\Project\Security\ReachableProject;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::MANAGE, subject: 'project')]
#[PublicPut(
    operationId: 'holdCard',
    summary: 'Make a card unmanaged',
    tags: ['Cards'],
    description: 'While the card is unmanaged, the workflow makes no move and starts no work on it, no bridge starts a worker on it, and a person may move it to any column. The hold cancels the work requests of the card that no bridge took yet. It stops no live run. A card already held answers 409 `already_paused`.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'number', description: 'The card number, the short label that counts from 1 inside this project.', in: 'path', required: true, schema: new OA\Schema(type: 'integer', minimum: 1)),
    ],
    responses: [
        new SuccessResponse(200, 'The card is now unmanaged.', CardHoldResponse::class),
        new ProblemResponse(404, ['project_not_found', 'card_not_found', 'route_not_found']),
        new ProblemResponse(409, ['already_paused']),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/cards/{number}/hold',
    name: 'api_card_hold',
    requirements: ['number' => CardApiRoutes::NUMBER],
    methods: ['PUT'],
)]
final class HoldCardController extends AppController
{
    public function __construct(
        private readonly FindCardByNumberHandler $findCard,
        private readonly PauseCardAgentsHandler $pause,
        private readonly CardApiProblems $problems,
    ) {
    }

    public function __invoke(#[ReachableProject] Project $project, int $number): JsonResponse
    {
        $card = ($this->findCard)(new FindCardByNumberCommand($project, $number)) ?? throw $this->problems->cardNotFound();
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');

        try {
            ($this->pause)(new PauseCardAgentsCommand($project, $cardId, $this->getUser() instanceof User ? $this->getUser() : throw new \LogicException('The route needs a signed-in account.'), 'agent'));
        } catch (DomainErrors $e) {
            throw $this->problems->fromHold($e);
        }

        return new JsonResponse(new CardHoldResponse((string) $cardId));
    }
}
