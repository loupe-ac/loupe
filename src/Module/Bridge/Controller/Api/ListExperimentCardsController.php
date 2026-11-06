<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Api\OpenApi\ProblemResponse;
use App\Api\OpenApi\PublicGet;
use App\Api\OpenApi\SuccessResponse;
use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Api\Pagination\PageRequest;
use App\Api\Problem\ApiProblemException;
use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Experiment\ExperimentCard;
use App\Module\Bridge\Experiment\ExperimentCardPosition;
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
    operationId: 'listExperimentCards',
    summary: 'List the cards of an experiment',
    tags: ['Experiments'],
    description: 'The cards that the Cards tab of the experiment lists, the latest run first and the cards with no run last. Each card names its variant, its pull request URLs, why the comparison leaves it out, and its manual exclusion. The state of the experiment can change between two pages of a walk, so a card can repeat or be missed.',
    parameters: [
        new OA\Parameter(name: 'project', description: 'A project id or a project slug. A slug changes when the project is renamed, so quote the id when it must stay valid.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'my-project'),
        new OA\Parameter(name: 'experiment', description: 'The name of the experiment, as the runs name it.', in: 'path', required: true, schema: new OA\Schema(type: 'string'), example: 'php-lsp-4'),
        new OA\Parameter(name: 'limit', description: 'The most rows to return.', in: 'query', schema: new OA\Schema(type: 'integer', default: PageRequest::DEFAULT_LIMIT, maximum: PageRequest::MAX_LIMIT, minimum: 1)),
        new OA\Parameter(name: 'cursor', description: 'The opaque position from the `next` URL of the last page.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'variant', description: 'Only the cards that count and ran this variant.', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'leftOut', description: 'True for only the cards the comparison leaves out, and false for only the cards that count.', in: 'query', schema: new OA\Schema(type: 'boolean')),
    ],
    responses: [
        new SuccessResponse(200, 'One page of cards.', ExperimentCardListResponse::class, page: true),
        new ProblemResponse(400, ['invalid_cursor']),
        new ProblemResponse(404, ['project_not_found', 'experiment_not_found', 'route_not_found']),
        new ProblemResponse(422, ['validation_failed'], 'ValidationProblem'),
    ],
)]
#[Route(
    '/api/v1/projects/{project}/experiments/{experiment}/cards',
    name: 'api_experiment_card_list',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    methods: ['GET'],
)]
final class ListExperimentCardsController extends AppController
{
    private const string SORT = 'lastRun';

    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
        private readonly ExperimentCardPayload $payload,
        private readonly ExperimentApiProblems $problems,
    ) {
    }

    public function __invoke(Request $request, #[ReachableProject] Project $project, string $experiment): JsonResponse
    {
        $variant = $request->query->get('variant');
        $leftOut = $request->query->get('leftOut');
        if (null !== $variant && (!\is_string($variant) || '' === $variant)) {
            throw ApiProblemException::validationFailed([['field' => 'variant', 'code' => 'invalid_value', 'message' => 'The variant must be a non-empty string.']]);
        }
        $leftOutOnly = match ($leftOut) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw ApiProblemException::validationFailed([['field' => 'leftOut', 'code' => 'invalid_value', 'message' => 'leftOut must be true or false.']]),
        };

        $filters = ['variant' => $variant, 'leftOut' => $leftOutOnly];
        $page = PageRequest::fromRequest($request, self::SORT, $filters);
        $after = null;
        if (null !== $page->cursor) {
            [$lastRunAt, $cardId] = $page->cursor->lastRunPosition();
            $after = new ExperimentCardPosition($lastRunAt, $cardId);
        }

        $view = ($this->showExperiment)(new ShowExperimentCommand(
            project: $project,
            experiment: $experiment,
            variant: $variant,
            leftOutOnly: true === $leftOutOnly,
            withMetrics: false,
            limit: $page->limit,
            after: $after,
            withPullRequestUrls: true,
            keptOnly: false === $leftOutOnly,
        )) ?? throw $this->problems->experimentNotFound();

        $next = null;
        $last = [] === $view->cards ? null : array_last($view->cards);
        if ($view->hasMore && null !== $last) {
            $next = Cursor::afterLastRun(self::SORT, $last->lastRunAt, $last->cardId, $filters);
        }

        return new Page($view->cards, $next)->toResponse($request, fn (ExperimentCard $card): array => $this->payload->forCard($card));
    }
}
