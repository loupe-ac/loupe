<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\Service\SavedGraphSettingsResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Appends a graph to the dashboard of a project, unless the project holds as many as the cap allows. */
final readonly class SaveSavedGraphHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphSettingsResolver $settings,
        private SavedGraphCap $cap,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SaveSavedGraphCommand $command): SavedGraphResult
    {
        $project = $command->project;
        $projectId = (string) $project->requireId();
        $query = $this->settings->resolve($project, $command->settings);
        $name = $this->settings->name($command->name, $query);

        $graph = $this->em->wrapInTransaction(function () use ($project, $query, $name): InsightsSavedGraph|DomainErrors {
            // The lock keeps two saves from sharing a position or passing the cap together.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            try {
                $this->cap->assertRoom($project);
            } catch (DomainErrors $e) {
                return $e;
            }
            $graph = new InsightsSavedGraph(
                $project,
                $name,
                $query->metric->key->key(),
                $query->unit,
                $query->statistic,
                $query->group,
                $query->range,
                $query->bucket,
                $query->compare,
                $query->chart,
                SavedGraphWidth::Half,
                true,
                $this->insightsSavedGraphs->nextPosition($project),
            );
            $this->em->persist($graph);

            return $graph;
        });
        if ($graph instanceof DomainErrors) {
            throw $graph;
        }

        $this->auditor->record(
            'insights.saved_graph_created',
            AuditOutcome::Success,
            ['projectId' => $projectId, 'graphId' => (string) $graph->id, 'metric' => $graph->metric, 'position' => $graph->position],
            new AuditSubject('project', $projectId),
        );

        return new SavedGraphResult($graph, $query);
    }
}
