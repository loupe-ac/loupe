<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphSettingsResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Replaces the settings of a graph that the dashboard still holds, and its name when the command carries one. */
final readonly class UpdateSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphSettingsResolver $settings,
        private Auditor $auditor,
    ) {
    }

    /** @throws NotFoundHttpException when the graph is removed, or belongs to another project */
    public function __invoke(UpdateSavedGraphCommand $command): SavedGraphResult
    {
        $project = $command->project;
        $projectId = (string) $project->requireId();
        $query = $this->settings->resolve($project, $command->settings);

        $graph = $this->em->wrapInTransaction(function () use ($command, $project, $projectId, $query): ?InsightsSavedGraph {
            // The lock orders the update against a save and against a removal of the same project.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            $graph = $this->insightsSavedGraphs->findOneByIdAndProjectId($command->graphId, $projectId);
            if (null === $graph || null !== $graph->removedAt) {
                return null;
            }
            $graph->metric = $query->metric->key->key();
            $graph->unit = $query->unit;
            $graph->statistic = $query->statistic;
            $graph->group = $query->group;
            $graph->range = $query->range;
            $graph->bucket = $query->bucket;
            $graph->compare = $query->compare;
            $graph->chartType = $query->chart;
            if ('' !== trim($command->name ?? '')) {
                $graph->name = $this->settings->name($command->name, $query);
            }

            return $graph;
        });
        if (null === $graph) {
            throw new NotFoundHttpException('The project has no such graph.');
        }

        $this->auditor->record(
            'insights.saved_graph_updated',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graph->id, 'metric' => $graph->metric],
            new AuditSubject('project', $projectId),
        );

        return new SavedGraphResult($graph, $query);
    }
}
