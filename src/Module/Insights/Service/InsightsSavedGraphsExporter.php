<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;

/** The saved graphs of the projects the user owns. A removed graph is left out. */
final readonly class InsightsSavedGraphsExporter implements UserDataExporterInterface
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'insights_saved_graphs.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->insightsSavedGraphs->findByOwner($user) as $graph) {
            yield [
                'projectId' => (string) $graph->project->id,
                'project' => $graph->project->name,
                'position' => $graph->position,
                'name' => $graph->name,
                'metric' => $graph->metric,
                'unit' => $graph->unit->value,
                'statistic' => $graph->statistic->value,
                'group' => $graph->group->value,
                'range' => $graph->range->value,
                'bucket' => $graph->bucket->value,
                'compare' => $graph->compare->value,
                'chartType' => $graph->chartType->value,
                'width' => $graph->width->value,
                'showLegend' => $graph->showLegend,
            ];
        }
    }
}
