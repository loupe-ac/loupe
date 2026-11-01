<?php

declare(strict_types=1);

namespace App\Module\Insights\Service\Dev;

use App\DevSeed\DevSeederInterface;
use App\DevSeed\DevSeedId;
use App\DevSeed\SeedContext;
use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Entity\InsightsSavedGraph;
use App\Module\Insights\Entity\SavedGraphWidth;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectCreating;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\Dev\DevProjectSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * Writes three more projects of the dev user, one for each edge state of the Metrics dashboard: a project with no
 * runs, a project that holds as many saved graphs as the default cap allows, and a project whose dashboard holds a
 * graph that names a bucket the project has no rule for. The graphs go in directly, because the save handler
 * refuses a save at the cap. A later seed restores a graph that is missing or removed, and keeps an edited graph.
 */
#[When('dev')]
#[When('test')]
final readonly class DevEdgeProjectSeeder implements DevSeederInterface
{
    public const string KEY = 'edge-projects';

    public const string NO_RUNS_NAME = 'Dev Project (no runs)';
    public const string AT_CAP_NAME = 'Dev Project (graph cap)';
    public const string BROKEN_GRAPH_NAME = 'Dev Project (broken graph)';

    /** The metrics of the graphs at the cap, each with the unit it reads. */
    private const array CAP_METRICS = [
        ['cost', MetricUnit::Run],
        ['cost', MetricUnit::Card],
        ['input-tokens', MetricUnit::Run],
        ['output-tokens', MetricUnit::Run],
        ['cache-read-tokens', MetricUnit::Run],
        ['cache-write-tokens', MetricUnit::Run],
        ['duration', MetricUnit::Run],
        ['duration', MetricUnit::Card],
        ['runs', MetricUnit::Card],
        ['stop-rate', MetricUnit::Run],
        ['input-tokens', MetricUnit::Card],
        ['output-tokens', MetricUnit::Card],
    ];

    public function __construct(
        private ProjectRepository $projects,
        private InsightsSavedGraphRepository $insightsSavedGraphs,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private DevProjectSeeder $devProjects,
    ) {
    }

    public static function noRunsProjectId(): Uuid
    {
        return DevSeedId::of('insights/project/no-runs');
    }

    public static function atCapProjectId(): Uuid
    {
        return DevSeedId::of('insights/project/graph-cap');
    }

    public static function brokenGraphProjectId(): Uuid
    {
        return DevSeedId::of('insights/project/broken-graph');
    }

    #[\Override]
    public function key(): string
    {
        return self::KEY;
    }

    #[\Override]
    public function dependsOn(): array
    {
        return [DevProjectSeeder::KEY];
    }

    #[\Override]
    public function seed(SeedContext $context): void
    {
        // Each project is older than "Dev Project" and than the one before it, so the project picker keeps its order.
        $this->project($context, self::noRunsProjectId(), self::NO_RUNS_NAME, 60);

        $atCap = $this->project($context, self::atCapProjectId(), self::AT_CAP_NAME, 120);
        foreach (self::CAP_METRICS as $position => [$metric, $unit]) {
            $name = \sprintf('%s per %s', ucfirst(str_replace('-', ' ', $metric)), $unit->value);
            $this->graph($context, 'graph-cap', $atCap, $name, $metric, $unit, $position, 3600 - $position * 60);
        }

        $broken = $this->project($context, self::brokenGraphProjectId(), self::BROKEN_GRAPH_NAME, 180);
        $this->graph($context, 'broken-graph', $broken, 'Cost per run', 'cost', MetricUnit::Run, 0, 120);
        $this->graph($context, 'broken-graph', $broken, 'Time in tests', 'bucket-time:tests', MetricUnit::Run, 1, 60);

        $this->em->flush();
    }

    private function project(SeedContext $context, Uuid $id, string $name, int $secondsAgo): Project
    {
        $existing = $this->projects->find($id);
        if ($existing instanceof Project) {
            return $existing;
        }

        $this->devProjects->renameOldProject($context->devUser(), $name);

        $project = new Project($context->devUser(), $name, createdAt: $context->at($secondsAgo));
        $context->ids->assign($project, $id);
        $this->em->persist($project);
        $this->events->dispatch(new ProjectCreating($project, 'simple'));
        $this->em->flush();

        return $project;
    }

    private function graph(SeedContext $context, string $list, Project $project, string $name, string $metric, MetricUnit $unit, int $position, int $secondsAgo): void
    {
        $id = DevSeedId::of(\sprintf('insights/graph/%s/%s-%s', $list, str_replace(':', '-', $metric), $unit->value));
        $stored = $this->insightsSavedGraphs->find($id);
        if ($stored instanceof InsightsSavedGraph) {
            // A removed graph keeps its row, and the project must still show this edge state.
            $stored->removedAt = null;

            return;
        }

        $graph = new InsightsSavedGraph(
            $project,
            $name,
            $metric,
            $unit,
            MetricStatistic::Mean,
            MetricGroup::None,
            MetricRange::ThirtyDays,
            MetricBucket::Day,
            MetricCompare::Off,
            MetricChartType::Line,
            SavedGraphWidth::Half,
            true,
            $position,
            $context->at($secondsAgo),
        );
        $context->ids->assign($graph, $id);
        $this->em->persist($graph);
    }
}
