<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Insights\Repository\InsightsKeptResultRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /** The kept object, or null when the payload no longer reads, as after a change of the class. */
    public static function decode(string $payload): ?object
    {
        try {
            $decoded = base64_decode($payload, true);
            $object = false === $decoded ? false : unserialize($decoded);
        } catch (\Throwable) {
            return null;
        }

        return \is_object($object) ? $object : null;
    }

    /** The hash that tells whether a kept result still fits the settings of a graph. A rename, a move or a resize keeps it. */
    public static function hashOf(InsightsSavedGraph $graph): string
    {
        return hash('sha256', implode('|', $graph->settings()));
    }
}
