<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller;

use App\Controller\AppController;
use App\Module\Bridge\Command\ShowExperimentCommand;
use App\Module\Bridge\Command\ShowExperimentHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\View\ExperimentCardsQuery;
use App\Module\Insights\Service\LiveRowsQuery;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Session\ReadOnlyAwareSessionHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;

/** The rows of a later page of the Cards tab, as a Turbo Stream: each shown card that still matches the filter morphs in place, the others go, and the band counts the newer ones. */
#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/analytics/experiments/{experiment}/cards/rows',
    name: 'app_project_analytics_experiment_cards_rows',
    requirements: ['experiment' => WorkerRun::EXPERIMENT_NAME],
    defaults: [ReadOnlyAwareSessionHandler::READ_ONLY => true],
    methods: ['GET'],
)]
class ListExperimentCardRowsController extends AppController
{
    public function __construct(
        private readonly ShowExperimentHandler $showExperiment,
    ) {
    }

    public function __invoke(Project $project, string $experiment, Request $request): Response
    {
        $rowsQuery = LiveRowsQuery::fromQuery($request->query);
        $query = ExperimentCardsQuery::fromQuery($request->query);
        $report = ($this->showExperiment)(new ShowExperimentCommand(
            $project,
            $experiment,
            variant: $query->variant,
            leftOutOnly: $query->leftOutOnly,
            withMetrics: false,
            onlyCards: $rowsQuery->ids,
            newerSince: $rowsQuery->since,
        )) ?? throw $this->createNotFoundException('No run and no pin name this experiment.');

        $cards = [];
        foreach ($report->cards as $card) {
            $cards[$card->cardId->toRfc4122()] = $card;
        }
        $html = $this->renderView('@Insights/_experiment_card_rows.stream.html.twig', [
            'project' => $project,
            'ids' => array_map(static fn ($id): string => $id->toRfc4122(), $rowsQuery->ids),
            'cards' => $cards,
            'newer' => $report->newerCards,
            'firstPage' => $this->generateUrl('app_project_analytics_experiment_cards', [
                'id' => (string) $project->id,
                'experiment' => $experiment,
                ...$query->withPage(1)->routeParams(),
            ]),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE]);
    }
}
