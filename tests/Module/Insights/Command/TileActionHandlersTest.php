<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Module\Insights\Command\MoveSavedGraphCommand;
use App\Module\Insights\Command\MoveSavedGraphHandler;
use App\Module\Insights\Command\RemoveSavedGraphCommand;
use App\Module\Insights\Command\RemoveSavedGraphHandler;
use App\Module\Insights\Command\RenameSavedGraphCommand;
use App\Module\Insights\Command\RenameSavedGraphHandler;
use App\Module\Insights\Command\ResizeSavedGraphCommand;
use App\Module\Insights\Command\ResizeSavedGraphHandler;
use App\Module\Insights\Command\SetLegendSavedGraphCommand;
use App\Module\Insights\Command\SetLegendSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TileActionHandlersTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_rename_trims_the_name_and_cuts_it_to_the_column(): void
    {
        $project = $this->scenarioProject('tile-rename');
        $graph = $this->seedSavedGraph($project, 'Old', 0);

        $this->service(RenameSavedGraphHandler::class)(new RenameSavedGraphCommand($graph, '  '.str_repeat('é', 130).'  '));

        $this->em()->clear();
        self::assertSame(120, mb_strlen($this->stored($graph)->name));
    }

    public function test_a_resize_and_a_legend_switch_change_only_their_own_field(): void
    {
        $project = $this->scenarioProject('tile-resize');
        $graph = $this->seedSavedGraph($project, 'Tests', 3);

        $this->service(ResizeSavedGraphHandler::class)(new ResizeSavedGraphCommand($graph, SavedGraphWidth::Full));
        $this->service(SetLegendSavedGraphHandler::class)(new SetLegendSavedGraphCommand($graph, false));

        $this->em()->clear();
        $stored = $this->stored($graph);
        self::assertSame(SavedGraphWidth::Full, $stored->width);
        self::assertFalse($stored->showLegend);
        self::assertSame('Tests', $stored->name);
        self::assertSame(3, $stored->position);
    }

    public function test_a_tile_action_on_a_removed_graph_is_a_not_found_and_changes_nothing(): void
    {
        $project = $this->scenarioProject('tile-removed');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('2026-10-01 10:00:00'));

        foreach ([
            fn () => $this->service(RenameSavedGraphHandler::class)(new RenameSavedGraphCommand($graph, 'Back')),
            fn () => $this->service(ResizeSavedGraphHandler::class)(new ResizeSavedGraphCommand($graph, SavedGraphWidth::Full)),
            fn () => $this->service(SetLegendSavedGraphHandler::class)(new SetLegendSavedGraphCommand($graph, false)),
            fn () => $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($graph, 1)),
            fn () => $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($graph)),
        ] as $action) {
            try {
                $action();
                self::fail('Expected a not found.');
            } catch (NotFoundHttpException) {
            }
            self::assertTrue($this->em()->isOpen());
        }

        $this->em()->clear();
        $stored = $this->stored($graph);
        self::assertSame('Gone', $stored->name);
        self::assertSame(SavedGraphWidth::Half, $stored->width);
        self::assertTrue($stored->showLegend);
        self::assertEquals(new \DateTimeImmutable('2026-10-01 10:00:00'), $stored->removedAt);
    }

    public function test_a_move_swaps_with_the_neighbour_on_show_and_skips_a_removed_graph(): void
    {
        $project = $this->scenarioProject('tile-move');
        $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'Removed', 1, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $b = $this->seedSavedGraph($project, 'B', 2);
        $this->seedSavedGraph($project, 'C', 3);

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($b, -1));

        self::assertSame(['B', 'A', 'C'], $this->names($project));
        self::assertSame(['B' => 0, 'Removed' => 1, 'A' => 2, 'C' => 3], $this->positions($project));
    }

    public function test_a_move_past_either_end_stops_at_the_end(): void
    {
        $project = $this->scenarioProject('tile-move-clamp');
        $a = $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);
        $this->seedSavedGraph($project, 'C', 2);

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($a, 50));
        self::assertSame(['B', 'C', 'A'], $this->names($project));

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($this->stored($a), -50));
        self::assertSame(['A', 'B', 'C'], $this->names($project));
    }

    public function test_a_move_of_several_places_shifts_the_others_by_one(): void
    {
        $project = $this->scenarioProject('tile-drag');
        $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);
        $c = $this->seedSavedGraph($project, 'C', 2);
        $this->seedSavedGraph($project, 'D', 3);

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($c, -2));

        self::assertSame(['C', 'A', 'B', 'D'], $this->names($project));
    }

    public function test_a_move_that_goes_nowhere_changes_nothing(): void
    {
        $project = $this->scenarioProject('tile-move-none');
        $a = $this->seedSavedGraph($project, 'A', 0);
        $this->seedSavedGraph($project, 'B', 1);

        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($a, -1));
        $this->service(MoveSavedGraphHandler::class)(new MoveSavedGraphCommand($a, 0));

        self::assertSame(['A' => 0, 'B' => 1], $this->positions($project));
    }

    public function test_a_remove_keeps_the_row_and_its_place(): void
    {
        $project = $this->scenarioProject('tile-remove');
        $this->seedSavedGraph($project, 'A', 0);
        $b = $this->seedSavedGraph($project, 'B', 1);

        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($b));

        $this->em()->clear();
        $stored = $this->stored($b);
        self::assertNotNull($stored->removedAt);
        self::assertSame(1, $stored->position);
        self::assertSame('B', $stored->name);
        self::assertSame(['A'], $this->names($project));
    }

    public function test_a_remove_deletes_the_graphs_removed_more_than_a_day_ago_and_no_others(): void
    {
        $project = $this->scenarioProject('tile-purge');
        $victim = $this->seedSavedGraph($project, 'Victim', 0);
        $old = $this->seedSavedGraph($project, 'Old', 1, new \DateTimeImmutable('-2 days'));
        $recent = $this->seedSavedGraph($project, 'Recent', 2, new \DateTimeImmutable('-2 hours'));
        $other = $this->seedSavedGraph($this->scenarioProject('tile-purge-other'), 'Other', 0, new \DateTimeImmutable('-2 days'));
        $ids = [(string) $old->id, (string) $recent->id, (string) $other->id];

        $this->service(RemoveSavedGraphHandler::class)(new RemoveSavedGraphCommand($victim));

        $this->em()->clear();
        self::assertNull($this->em()->find(InsightsSavedGraph::class, $ids[0]));
        self::assertNotNull($this->em()->find(InsightsSavedGraph::class, $ids[1]));
        self::assertNotNull($this->em()->find(InsightsSavedGraph::class, $ids[2]));
        self::assertNotNull($this->stored($victim)->removedAt);
    }

    /** @return list<string> the names of the graphs on show, in order, read fresh */
    private function names(Project $project): array
    {
        $this->em()->clear();
        $stored = $this->em()->find($project::class, $project->id) ?? throw new \LogicException('The project exists.');

        return array_map(static fn (InsightsSavedGraph $graph): string => $graph->name, $this->service(InsightsSavedGraphRepository::class)->findActive($stored));
    }

    /** @return array<string, int> the position of every graph of the project, a removed one included */
    private function positions(Project $project): array
    {
        $this->em()->clear();
        $rows = $this->em()->getConnection()->fetchAllKeyValue('SELECT name, position FROM insights_saved_graphs WHERE project_id = :id ORDER BY position', ['id' => (string) $project->id]);

        return array_map(intval(...), $rows);
    }

    private function stored(InsightsSavedGraph $graph): InsightsSavedGraph
    {
        return $this->em()->find(InsightsSavedGraph::class, $graph->id) ?? throw new \LogicException('The graph exists.');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
