<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Bridge\Experiment\Confidence;
use App\Module\Bridge\Experiment\ExperimentArm;
use App\Module\Bridge\Experiment\ExperimentComparison;
use App\Module\Bridge\Experiment\ExperimentControl;
use App\Module\Bridge\Experiment\ExperimentGoal;
use App\Module\Bridge\Experiment\ExperimentGuard;
use App\Module\Bridge\Experiment\ExperimentGuardChange;
use App\Module\Bridge\Experiment\ExperimentHeadline;
use App\Module\Bridge\Experiment\ExperimentMetric;
use App\Module\Bridge\Experiment\ExperimentVariant;
use App\Module\Bridge\Experiment\GoalHold;
use App\Module\Bridge\Experiment\Interval;
use App\Module\Bridge\Metric\Formula\BinaryNode;
use App\Module\Bridge\Metric\Formula\FormulaProblem;
use App\Module\Bridge\Metric\Formula\FormulaRow;
use App\Module\Bridge\Metric\Formula\LetterNode;
use App\Module\Bridge\Metric\Formula\MetricFormula;
use App\Module\Bridge\Metric\Formula\NumberNode;
use App\Module\Bridge\Metric\Formula\SignNode;
use App\Module\Bridge\Metric\MetricComparison;
use App\Module\Bridge\Metric\MetricGroupKey;
use App\Module\Bridge\Metric\MetricKey;
use App\Module\Bridge\Metric\MetricPoint;
use App\Module\Bridge\Metric\MetricRow;
use App\Module\Bridge\Metric\MetricSeries;
use App\Module\Bridge\Metric\MetricShape;
use App\Module\Bridge\Metric\MetricWindow;
use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Insights\View\KeptGraphResult;
use App\Module\Insights\View\MetricsQuery;
use App\Module\Insights\View\PublicExperimentResult;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CountFilter;
use App\Module\Workflow\Contract\DurationEvent;
use App\Module\Workflow\Contract\TemplateMetric;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\MaxUuid;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV1;
use Symfony\Component\Uid\UuidV3;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV5;
use Symfony\Component\Uid\UuidV6;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Uid\UuidV8;

/**
 * The last result that the worker or a share page computed for one graph or one shared experiment. It never expires,
 * and a newer compute replaces it. The repository reads and writes the table with the connection, so this class
 * serves the schema and the helpers of the payload.
 */
#[ORM\Entity(repositoryClass: InsightsKeptResultRepository::class)]
#[ORM\Table(name: 'insights_kept_results')]
#[ORM\UniqueConstraint(name: 'uniq_insights_kept_result_graph', columns: ['saved_graph_id'])]
#[ORM\UniqueConstraint(name: 'uniq_insights_kept_result_experiment', columns: ['project_id', 'experiment'])]
class InsightsKeptResult
{
    /**
     * Every class a kept result holds. Enums are absent because allowed_classes does not apply to them. The Uuid forms
     * are present because Uuid::fromString() returns the concrete version class.
     */
    public const array RESULT_CLASSES = [
        KeptGraphResult::class, PublicExperimentResult::class, MetricsQuery::class,
        MetricSeries::class, MetricPoint::class, MetricRow::class, MetricComparison::class, MetricGroupKey::class,
        MetricKey::class, MetricShape::class, MetricWindow::class,
        MetricFormula::class, FormulaRow::class, FormulaProblem::class,
        LetterNode::class, BinaryNode::class, SignNode::class, NumberNode::class,
        ExperimentHeadline::class, ExperimentGoal::class, ExperimentMetric::class, ExperimentVariant::class,
        ExperimentArm::class, Confidence::class, Interval::class, ExperimentComparison::class,
        ExperimentGuard::class, ExperimentControl::class, ExperimentGuardChange::class, GoalHold::class,
        TemplateMetric::class, CountFilter::class, DurationEvent::class,
        \DateTimeImmutable::class,
        Uuid::class, UuidV1::class, UuidV3::class, UuidV4::class, UuidV5::class, UuidV6::class, UuidV7::class,
        UuidV8::class, NilUuid::class, MaxUuid::class,
    ];

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        /** Set for the result of a graph, null for the result of an experiment. */
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: InsightsSavedGraph::class)]
        public readonly ?InsightsSavedGraph $savedGraph,

        /** Set for the result of a shared experiment, null for the result of a graph. */
        #[ORM\Column(name: 'experiment', length: 255, nullable: true)]
        public readonly ?string $experiment,

        /** The hash of the graph settings at compute time, empty for an experiment. */
        #[ORM\Column(name: 'settings_hash', length: 64)]
        public string $settingsHash,

        /** When the compute took its lock, on the clock of the database. */
        #[ORM\Column(name: 'computed_at', type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $computedAt,

        /** The KeptGraphResult or PublicExperimentResult, serialized and base64 encoded, because a text column cannot hold the NUL bytes of a serialized object. */
        #[ORM\Column(name: 'payload', type: Types::TEXT)]
        public string $payload,
    ) {
    }

    public static function encode(object $result): string
    {
        return base64_encode(serialize($result));
    }

    /** The kept result, or null when the payload no longer reads, names a foreign class, or holds another root. */
    public static function decode(string $payload): KeptGraphResult|PublicExperimentResult|null
    {
        try {
            $decoded = base64_decode($payload, true);
            $object = false === $decoded ? false : unserialize($decoded, ['allowed_classes' => self::RESULT_CLASSES]);
        } catch (\Throwable) {
            return null;
        }

        return $object instanceof KeptGraphResult || $object instanceof PublicExperimentResult ? $object : null;
    }

    /** The hash that tells whether a kept result still fits the settings of a graph. A rename, a move or a resize keeps it. */
    public static function hashOf(InsightsSavedGraph $graph): string
    {
        return hash('sha256', implode('|', $graph->settings()));
    }
}
