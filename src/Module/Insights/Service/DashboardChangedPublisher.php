<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Mercure\LiveUpdatePublisher;
use App\Mercure\ProjectTopicBuilder;
use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the open Dashboards of a project that a saved graph changed. The message
 * names the graph and the kind of change, and carries no name and no number.
 */
final readonly class DashboardChangedPublisher
{
    public const string TYPE = 'dashboard.graph_changed';

    public function __construct(
        private ProjectTopicBuilder $topics,
        private LiveUpdatePublisher $publisher,
    ) {
    }

    /** Call it after the change commits, so a rollback signals nothing. */
    public function graphChanged(Project $project, Uuid $graphId, DashboardChange $change): void
    {
        $this->publisher->queue(
            $this->topics->forDashboard($project->requireId()),
            ['type' => self::TYPE, 'graphId' => (string) $graphId, 'kind' => $change->value],
        );
    }
}
