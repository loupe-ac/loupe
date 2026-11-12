<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\InsightsKeptResult;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\DashboardChange;
use App\Module\Insights\Service\DashboardChangedPublisher;
use App\Module\Insights\Service\InsightsRefreshRequester;
use App\Module\Insights\Service\SavedGraphLocker;
use App\Module\Insights\Service\SavedGraphOrder;
use App\Module\Insights\Service\SavedGraphSettingsResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Changes the settings, the name, the width, the legend and the place of a graph that the Dashboard still holds.
 * All changes commit in one transaction under one lock, so a failure leaves the graph as it was.
 */
final readonly class UpdateSavedGraphFieldsHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphLocker $locker,
        private SavedGraphSettingsResolver $settings,
        private SavedGraphOrder $order,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
        private InsightsRefreshRequester $refreshes,
    ) {
    }

    /**
     * @throws DomainErrors          on the field `chart` when the settings lock the chart type
     * @throws NotFoundHttpException when the graph is removed, or belongs to another project
     */
    public function __invoke(UpdateSavedGraphFieldsCommand $command): UpdatedSavedGraph
    {
        $project = $command->project;
        $projectId = (string) $project->requireId();
        $query = null === $command->settings ? null : $this->settings->resolve($project, $command->settings);
        $name = mb_substr(trim($command->name ?? ''), 0, InsightsSavedGraph::MAX_NAME_LENGTH);

        $applied = $this->em->wrapInTransaction(function () use ($command, $projectId, $query, $name): ?array {
            // A caller can hold the graph already, so its fields are read again under the lock.
            $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->graphId, $projectId);
            if (null === $graph || !$this->locker->lockActive($graph)) {
                return null;
            }

            $settingsChanged = false;
            if (null !== $query) {
                $before = InsightsKeptResult::hashOf($graph);
                $graph->metric = $query->metric->key->key();
                $graph->unit = $query->unit;
                $graph->statistic = $query->statistic;
                $graph->group = $query->group;
                $graph->range = $query->range;
                $graph->bucket = $query->bucket;
                $graph->compare = $query->compare;
                $graph->chartType = $query->chart;
                $graph->formulaRows = $query->formula?->rowsText();
                $graph->formula = $query->formula?->formula;
                $graph->format = $query->formula?->format->value;
                $settingsChanged = $before !== InsightsKeptResult::hashOf($graph);
            }
            if ('' !== $name) {
                $graph->name = $name;
            }
            if (null !== $command->width) {
                $graph->width = $command->width;
            }
            if (null !== $command->showLegend) {
                $graph->showLegend = $command->showLegend;
            }
            $moved = null !== $command->position && $this->order->moveTo($graph, $command->position);

            return ['graph' => $graph, 'settingsChanged' => $settingsChanged, 'moved' => $moved];
        });
        if (null === $applied) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        $graph = $applied['graph'];
        $graphId = $graph->id ?? throw new \LogicException('A stored graph has an id.');
        $subject = new AuditSubject('project', $projectId);
        $context = ['projectId' => $projectId, 'graphId' => (string) $graphId];

        if (null !== $query) {
            $this->auditor->record('insights.saved_graph_updated', AuditOutcome::Success, $context + ['metric' => $graph->metric], $subject);
            $this->dashboard->graphChanged($project, $graphId, DashboardChange::Updated);
        } elseif ('' !== $name) {
            $this->auditor->record('insights.saved_graph_renamed', AuditOutcome::Success, $context, $subject);
            $this->dashboard->graphChanged($project, $graphId, DashboardChange::Renamed);
        }
        if (null !== $command->width) {
            $this->auditor->record('insights.saved_graph_resized', AuditOutcome::Success, $context + ['width' => $command->width->value], $subject);
            $this->dashboard->graphChanged($project, $graphId, DashboardChange::Resized);
        }
        if (null !== $command->showLegend) {
            $this->auditor->record('insights.saved_graph_legend_changed', AuditOutcome::Success, $context + ['showLegend' => $command->showLegend], $subject);
            $this->dashboard->graphChanged($project, $graphId, DashboardChange::LegendChanged);
        }
        if ($applied['moved']) {
            $this->auditor->record('insights.saved_graph_moved', AuditOutcome::Success, $context + ['position' => $command->position], $subject);
            $this->dashboard->graphChanged($project, $graphId, DashboardChange::Moved);
        }
        if ($applied['settingsChanged']) {
            $this->refreshes->graph($graph);
        }

        return new UpdatedSavedGraph($graph, $query);
    }
}
