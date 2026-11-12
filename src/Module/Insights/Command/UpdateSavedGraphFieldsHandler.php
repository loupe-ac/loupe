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
use App\Module\Insights\Service\SavedGraphChangeRefused;
use App\Module\Insights\Service\SavedGraphLocker;
use App\Module\Insights\Service\SavedGraphOrder;
use App\Module\Insights\Service\SavedGraphSettingsInvalid;
use App\Module\Insights\Service\SavedGraphSettingsMerger;
use App\Module\Insights\Service\SavedGraphSettingsProblems;
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
        private SavedGraphSettingsProblems $settingsProblems,
        private SavedGraphOrder $order,
        private Auditor $auditor,
        private DashboardChangedPublisher $dashboard,
        private InsightsRefreshRequester $refreshes,
    ) {
    }

    /**
     * @throws DomainErrors              on the field `chart` when the settings lock the chart type
     * @throws SavedGraphChangeRefused   when a partial change contradicts the settings the graph keeps
     * @throws SavedGraphSettingsInvalid when the merged settings hold a value that the metric or the range cannot take
     * @throws NotFoundHttpException     when the graph is removed, or belongs to another project
     */
    public function __invoke(UpdateSavedGraphFieldsCommand $command): UpdatedSavedGraph
    {
        $project = $command->project;
        $projectId = (string) $project->requireId();
        $query = null === $command->settings ? null : $this->settings->resolve($project, $command->settings);
        $change = $command->change;
        if (null !== $change && [] === $change->passed && [] === $change->formulaPassed && !$change->clearFormula) {
            $change = null;
        }
        $name = mb_substr(trim($command->name ?? ''), 0, InsightsSavedGraph::MAX_NAME_LENGTH);

        $applied = $this->em->wrapInTransaction(function () use ($command, $change, $projectId, $query, $name): ?array {
            // A caller can hold the graph already, so its fields are read again under the lock.
            $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->graphId, $projectId);
            if (null === $graph || !$this->locker->lockActive($graph)) {
                return null;
            }

            if (null !== $change) {
                // A refusal is returned, not thrown, because a thrown exception closes the entity manager.
                try {
                    $merged = SavedGraphSettingsMerger::merge($graph, $change->passed, $change->formulaPassed, $change->clearFormula);
                    $invalid = $this->settingsProblems->find($command->project, $merged);
                    if ([] !== $invalid) {
                        return ['refused' => new SavedGraphSettingsInvalid($invalid)];
                    }
                    $query = $merged !== $graph->settings() ? $this->settings->resolve($command->project, $merged) : null;
                } catch (SavedGraphChangeRefused|DomainErrors $refused) {
                    return ['refused' => $refused];
                }
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

            return ['graph' => $graph, 'query' => $query, 'settingsChanged' => $settingsChanged, 'moved' => $moved];
        });
        if (null === $applied) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        if (isset($applied['refused'])) {
            throw $applied['refused'];
        }

        $graph = $applied['graph'];
        $query = $applied['query'];
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
