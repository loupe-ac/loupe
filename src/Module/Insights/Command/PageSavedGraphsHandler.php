<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Api\Pagination\Cursor;
use App\Api\Pagination\Page;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\PositionCursor;
use App\Module\Insights\Service\SavedGraphHealth;
use Psr\Clock\ClockInterface;

/**
 * Reads a page of the saved graphs of a project in order of position, then id. The Dashboard holds
 * at most the cap of graphs, and a removed graph stays one day, so the page is cut from a short list.
 */
final readonly class PageSavedGraphsHandler
{
    public function __construct(
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private SavedGraphHealth $health,
        private ClockInterface $clock,
    ) {
    }

    /** @return Page<SavedGraphRow> */
    public function __invoke(PageSavedGraphsCommand $command): Page
    {
        $project = $command->project;
        $graphs = $command->removed
            ? $this->insightsSavedGraphs->findAllRestorable($project, \DateTimeImmutable::createFromInterface($this->clock->now()))
            : $this->insightsSavedGraphs->findActive($project);

        $after = null !== $command->page->cursor ? PositionCursor::positionOf($command->page->cursor) : null;
        $kept = [];
        foreach ($graphs as $index => $graph) {
            if (null === $after || PositionCursor::isAfter($graph->position, $graph->id ?? throw new \LogicException('A stored graph has an id.'), $after[0], $after[1])) {
                $kept[] = [$index, $graph];
            }
        }
        $window = \array_slice($kept, 0, $command->page->fetchSize());

        $broken = $command->removed ? [] : $this->health->check($project, array_map(static fn (array $entry): InsightsSavedGraph => $entry[1], $window));
        $rows = array_map(
            static fn (array $entry): SavedGraphRow => new SavedGraphRow($entry[1], $command->removed ? null : $entry[0], $broken[(string) $entry[1]->id] ?? null),
            $window,
        );

        return Page::fromRows($rows, $command->page, static fn (SavedGraphRow $row): Cursor => PositionCursor::after(
            $row->graph->position,
            $row->graph->id ?? throw new \LogicException('A stored graph has an id.'),
            ['removed' => $command->removed],
        ));
    }
}
