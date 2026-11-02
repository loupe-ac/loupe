<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Bridge\Metric\MetricBucket;
use App\Module\Bridge\Metric\MetricCompare;
use App\Module\Bridge\Metric\MetricGroup;
use App\Module\Bridge\Metric\MetricRange;
use App\Module\Bridge\Metric\MetricStatistic;
use App\Module\Bridge\Metric\MetricUnit;
use App\Module\Insights\Repository\InsightsSavedGraphRepository;
use App\Module\Insights\View\MetricChartType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** One graph that a project keeps on its dashboard. A removed graph keeps its row and stops counting. */
#[ORM\Entity(repositoryClass: InsightsSavedGraphRepository::class)]
#[ORM\Index(name: 'idx_insights_saved_graph_position', columns: ['project_id', 'position'])]
#[ORM\Table(name: 'insights_saved_graphs')]
class InsightsSavedGraph
{
    public const int MAX_NAME_LENGTH = 120;

    public const int MAX_METRIC_LENGTH = 140;

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'name', length: self::MAX_NAME_LENGTH)]
        public string $name,

        /** A metric key, such as `bucket-time:tests`. */
        #[ORM\Column(name: 'metric', length: self::MAX_METRIC_LENGTH)]
        public string $metric,

        #[ORM\Column(name: 'unit', length: 16, enumType: MetricUnit::class)]
        public MetricUnit $unit,

        #[ORM\Column(name: 'statistic', length: 16, enumType: MetricStatistic::class)]
        public MetricStatistic $statistic,

        #[ORM\Column(name: 'metric_group', length: 32, enumType: MetricGroup::class)]
        public MetricGroup $group,

        #[ORM\Column(name: 'range', length: 16, enumType: MetricRange::class)]
        public MetricRange $range,

        #[ORM\Column(name: 'bucket', length: 16, enumType: MetricBucket::class)]
        public MetricBucket $bucket,

        #[ORM\Column(name: 'compare', length: 16, enumType: MetricCompare::class)]
        public MetricCompare $compare,

        #[ORM\Column(name: 'chart_type', length: 32, enumType: MetricChartType::class)]
        public MetricChartType $chartType,

        #[ORM\Column(name: 'width', length: 8, enumType: SavedGraphWidth::class)]
        public SavedGraphWidth $width,

        #[ORM\Column(name: 'show_legend')]
        public bool $showLegend,

        #[ORM\Column(name: 'position')]
        public int $position,

        #[ORM\Column(name: 'created_at')]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),

        #[ORM\Column(name: 'removed_at', nullable: true)]
        public ?\DateTimeImmutable $removedAt = null,
    ) {
    }

    /** A removed graph stays restorable for this long, then the next remove deletes it. */
    public const string UNDO_WINDOW = '-1 day';

    public function isUndoable(\DateTimeImmutable $now): bool
    {
        return null !== $this->removedAt && $this->removedAt >= $now->modify(self::UNDO_WINDOW);
    }

    /** The end of the Undo window, or null for a graph on show. */
    public function undoUntil(): ?\DateTimeImmutable
    {
        return $this->removedAt?->sub(\DateInterval::createFromDateString(self::UNDO_WINDOW));
    }

    /** @return array<string, string> the Metrics controls of the graph, by name */
    public function settings(): array
    {
        return [
            'metric' => $this->metric,
            'unit' => $this->unit->value,
            'statistic' => $this->statistic->value,
            'group' => $this->group->value,
            'range' => $this->range->value,
            'bucket' => $this->bucket->value,
            'compare' => $this->compare->value,
            'chart' => $this->chartType->value,
        ];
    }
}
