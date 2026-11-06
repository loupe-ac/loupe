<?php

declare(strict_types=1);

namespace App\Module\Insights\Entity;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Insights\Repository\PublicLinkRepository;
use App\Module\Insights\Service\PublicLinkCode;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One shared item of a project: a saved graph or an experiment. The code never changes, and the switch only
 * turns the link on or off. A database check keeps exactly one target for each kind.
 */
#[ORM\Entity(repositoryClass: PublicLinkRepository::class)]
#[ORM\Table(name: 'insights_public_links')]
#[ORM\UniqueConstraint(name: 'uniq_insights_public_link_experiment', columns: ['project_id', 'experiment'])]
class PublicLink
{
    public const string FLAG = 'insights.public_links.enabled';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(name: 'kind', length: 16, enumType: PublicLinkKind::class)]
        public readonly PublicLinkKind $kind,

        #[ORM\Column(name: 'code', length: PublicLinkCode::LENGTH, unique: true)]
        public readonly string $code,

        #[ORM\JoinColumn(name: 'saved_graph_id', nullable: true, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InsightsSavedGraph::class)]
        public readonly ?InsightsSavedGraph $savedGraph = null,

        #[ORM\Column(name: 'experiment', length: WorkerRun::MAX_EXPERIMENT_NAME_LENGTH, nullable: true)]
        public readonly ?string $experiment = null,

        #[ORM\Column(name: 'enabled')]
        public bool $enabled = true,

        #[ORM\Column(name: 'note', type: 'text', nullable: true)]
        public ?string $note = null,

        #[ORM\Column(name: 'created_at')]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $targets = (null === $savedGraph ? 0 : 1) + (null === $experiment ? 0 : 1);
        $expected = match ($kind) {
            PublicLinkKind::Graph => null !== $savedGraph && null === $experiment,
            PublicLinkKind::Experiment => null !== $experiment && null === $savedGraph,
        };
        if (!$expected || 1 !== $targets) {
            throw new \InvalidArgumentException('A public link names exactly one target, and the target matches the kind.');
        }
    }

    public static function forGraph(InsightsSavedGraph $savedGraph): self
    {
        return new self($savedGraph->project, PublicLinkKind::Graph, PublicLinkCode::generate(), savedGraph: $savedGraph);
    }

    public static function forExperiment(Project $project, string $experiment, ?string $note = null): self
    {
        return new self($project, PublicLinkKind::Experiment, PublicLinkCode::generate(), experiment: $experiment, note: $note);
    }
}
