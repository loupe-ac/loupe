<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Command\SavedGraphResult;
use App\Module\Insights\Command\SaveSavedGraphCommand;
use App\Module\Insights\Command\SaveSavedGraphHandler;
use App\Module\Insights\Command\UpdateSavedGraphCommand;
use App\Module\Insights\Command\UpdateSavedGraphHandler;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\Service\SavedGraphCap;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SavedGraphHandlersTest extends KernelTestCase
{
    use InsightsScenario;

    private const array SETTINGS = [
        'metric' => 'cost',
        'unit' => 'run',
        'statistic' => 'sum',
        'group' => 'none',
        'range' => 'thirty-days',
        'bucket' => 'day',
        'compare' => 'previous',
        'chart' => 'bars',
    ];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_saved_graph_keeps_every_setting_and_goes_last(): void
    {
        $project = $this->scenarioProject('graph-save');
        $this->seedSavedGraph($project, 'Existing', 4);

        $result = $this->save($project, 'My cost', self::SETTINGS);

        $graph = $result->graph;
        self::assertSame(5, $graph->position);
        self::assertSame('My cost', $graph->name);
        self::assertSame('cost', $graph->metric);
        self::assertSame(MetricUnit::Run, $graph->unit);
        self::assertSame(MetricStatistic::Sum, $graph->statistic);
        self::assertSame(MetricGroup::None, $graph->group);
        self::assertSame(MetricRange::ThirtyDays, $graph->range);
        self::assertSame(MetricBucket::Day, $graph->bucket);
        self::assertSame(MetricCompare::Previous, $graph->compare);
        self::assertSame(MetricChartType::Bars, $graph->chartType);
        self::assertSame(SavedGraphWidth::Half, $graph->width);
        self::assertTrue($graph->showLegend);
        self::assertNull($graph->removedAt);
        self::assertSame(['Existing', 'My cost'], $this->names($project));
    }

    public function test_the_first_graph_takes_position_zero(): void
    {
        self::assertSame(0, $this->save($this->scenarioProject('graph-first'), 'One', self::SETTINGS)->graph->position);
    }

    /** @return iterable<string, array{?string}> */
    public static function emptyNames(): iterable
    {
        yield 'null' => [null];
        yield 'an empty name' => [''];
        yield 'spaces' => ['   '];
    }

    #[DataProvider('emptyNames')]
    public function test_an_empty_name_falls_back_to_the_title(?string $name): void
    {
        $project = $this->scenarioProject('graph-title-'.uniqid());

        $graph = $this->save($project, $name, self::SETTINGS)->graph;

        self::assertSame('Cost per run, sum, last 30 days vs the 30 days before, per day', $graph->name);
    }

    public function test_a_title_longer_than_a_name_is_cut_to_fit_the_column(): void
    {
        $project = $this->scenarioProject('graph-title-long');
        $this->em()->flush();

        $graph = $this->save($project, str_repeat('é', 120), self::SETTINGS)->graph;

        self::assertSame(120, mb_strlen($graph->name));
    }

    public function test_a_combination_the_page_rejects_is_normalised(): void
    {
        $project = $this->scenarioProject('graph-normalise');

        $result = $this->save($project, null, ['metric' => 'nonsense', 'range' => 'all', 'compare' => 'previous', 'chart' => 'bogus'] + self::SETTINGS);

        self::assertSame('cost', $result->graph->metric);
        self::assertSame(MetricCompare::Off, $result->graph->compare);
        self::assertSame(MetricChartType::Line, $result->graph->chartType);
        self::assertSame(MetricCompare::Off, $result->query->compare);
    }

    /** @return iterable<string, array{string, string}> */
    public static function lockedCharts(): iterable
    {
        yield 'a median does not add' => ['stacked-bars', 'median'];
        yield 'one line has nothing to add' => ['donut', 'sum'];
    }

    #[DataProvider('lockedCharts')]
    public function test_a_chart_type_the_settings_lock_is_refused_not_switched(string $chart, string $statistic): void
    {
        $project = $this->scenarioProject('graph-locked-'.uniqid());

        try {
            $this->save($project, 'Locked', ['chart' => $chart, 'statistic' => $statistic] + self::SETTINGS);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['chart'], array_keys($e->errors));
        }

        self::assertTrue($this->em()->isOpen());
        self::assertSame([], $this->names($project));
    }

    public function test_a_project_at_the_cap_is_refused_and_another_project_is_not(): void
    {
        $project = $this->scenarioProject('graph-cap');
        for ($i = 0; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }

        try {
            $this->save($project, 'One too many', self::SETTINGS);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['name' => SavedGraphCap::ERROR_KEY], $e->errors);
            self::assertSame(['name' => ['%limit%' => 12]], $e->parameters);
        }

        self::assertTrue($this->em()->isOpen());
        self::assertCount(12, $this->names($project));
        self::assertSame(0, $this->save($this->scenarioProject('graph-cap-other'), 'Fine', self::SETTINGS)->graph->position);
    }

    public function test_a_removed_graph_frees_a_slot_and_the_next_save_after_it_is_refused_again(): void
    {
        $project = $this->scenarioProject('graph-cap-sequence');
        $graphs = [];
        for ($i = 0; $i < 12; ++$i) {
            $graphs[] = $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }
        $graphs[3]->removedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $saved = $this->save($project, 'Replacement', self::SETTINGS)->graph;

        self::assertSame(12, $saved->position);
        try {
            $this->save($project, 'Second', self::SETTINGS);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['name'], array_keys($e->errors));
        }
        self::assertCount(12, $this->names($project));
    }

    public function test_an_update_changes_the_settings_and_the_name(): void
    {
        $project = $this->scenarioProject('graph-update');
        $graph = $this->seedSavedGraph($project, 'Tests', 2);

        $result = $this->update($project, (string) $graph->id, 'Renamed', ['chart' => 'line', 'statistic' => 'p90', 'range' => 'seven-days', 'bucket' => 'day', 'compare' => 'off'] + self::SETTINGS);

        $this->em()->clear();
        $stored = $this->em()->find(InsightsSavedGraph::class, $graph->id) ?? throw new \LogicException('The graph exists.');
        self::assertSame('Renamed', $stored->name);
        self::assertSame(MetricStatistic::P90, $stored->statistic);
        self::assertSame(MetricRange::SevenDays, $stored->range);
        self::assertSame('cost', $stored->metric);
        self::assertSame(MetricChartType::Line, $stored->chartType);
        self::assertSame(2, $stored->position);
        self::assertSame(SavedGraphWidth::Half, $stored->width);
        self::assertSame((string) $graph->id, (string) $result->graph->id);
        self::assertSame(MetricStatistic::P90, $result->query->statistic);
    }

    public function test_an_update_without_a_name_keeps_the_name(): void
    {
        $project = $this->scenarioProject('graph-update-keep');
        $graph = $this->seedSavedGraph($project, 'Keep me', 0);

        $this->update($project, (string) $graph->id, null, self::SETTINGS);

        $this->em()->clear();
        self::assertSame(['Keep me'], $this->names($project));
    }

    public function test_an_update_works_at_the_cap(): void
    {
        $project = $this->scenarioProject('graph-update-cap');
        $first = $this->seedSavedGraph($project, 'Graph 0', 0);
        for ($i = 1; $i < 12; ++$i) {
            $this->seedSavedGraph($project, 'Graph '.$i, $i);
        }

        $this->update($project, (string) $first->id, 'Changed', self::SETTINGS);

        self::assertCount(12, $this->names($project));
    }

    public function test_an_update_refuses_a_locked_chart_and_changes_nothing(): void
    {
        $project = $this->scenarioProject('graph-update-locked');
        $graph = $this->seedSavedGraph($project, 'Tests', 0);

        try {
            $this->update($project, (string) $graph->id, 'Renamed', ['chart' => 'donut', 'statistic' => 'median'] + self::SETTINGS);
            self::fail('Expected a refusal.');
        } catch (DomainErrors $e) {
            self::assertSame(['chart'], array_keys($e->errors));
        }

        $this->em()->clear();
        self::assertSame(['Tests'], $this->names($project));
    }

    public function test_an_update_of_a_removed_graph_is_a_not_found(): void
    {
        $project = $this->scenarioProject('graph-update-removed');
        $graph = $this->seedSavedGraph($project, 'Gone', 0, new \DateTimeImmutable('2026-10-01 10:00:00'));

        $this->expectException(NotFoundHttpException::class);
        $this->update($project, (string) $graph->id, 'Back', self::SETTINGS);
    }

    public function test_an_update_of_a_graph_of_another_project_is_a_not_found_and_changes_nothing(): void
    {
        $project = $this->scenarioProject('graph-update-mine');
        $theirs = $this->seedSavedGraph($this->scenarioProject('graph-update-theirs'), 'Theirs', 0);

        try {
            $this->update($project, (string) $theirs->id, 'Mine now', self::SETTINGS);
            self::fail('Expected a not found.');
        } catch (NotFoundHttpException) {
        }

        $this->em()->clear();
        $stored = $this->em()->find(InsightsSavedGraph::class, $theirs->id) ?? throw new \LogicException('The graph exists.');
        self::assertSame('Theirs', $stored->name);
    }

    public function test_an_update_with_a_malformed_id_is_a_not_found(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->update($this->scenarioProject('graph-update-malformed'), 'not-an-id', null, self::SETTINGS);
    }

    /** @param array<string, string> $settings */
    private function save(Project $project, ?string $name, array $settings): SavedGraphResult
    {
        return $this->service(SaveSavedGraphHandler::class)(new SaveSavedGraphCommand($project, $name, $settings));
    }

    /** @param array<string, string> $settings */
    private function update(Project $project, string $graphId, ?string $name, array $settings): SavedGraphResult
    {
        return $this->service(UpdateSavedGraphHandler::class)(new UpdateSavedGraphCommand($project, $graphId, $name, $settings));
    }

    /** @return list<string> the names of the active graphs in order of position, read fresh */
    private function names(Project $project): array
    {
        $this->em()->clear();
        $stored = $this->em()->find($project::class, $project->id) ?? throw new \LogicException('The project exists.');

        return array_map(static fn (InsightsSavedGraph $graph): string => $graph->name, $this->service(InsightsSavedGraphRepository::class)->findActive($stored));
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
