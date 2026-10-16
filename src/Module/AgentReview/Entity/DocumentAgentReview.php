<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** What an agent found when it reviewed one version of a draft design of a card, and how the fix round answered it. */
#[ORM\Entity(repositoryClass: DocumentAgentReviewRepository::class)]
#[ORM\Index(name: 'idx_document_agent_reviews_document_created', columns: ['document_id', 'created_at'])]
#[ORM\Table(name: 'document_agent_reviews')]
class DocumentAgentReview
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** @var list<array{path: ?string, startLine: ?int, endLine: ?int, severity: string, title: string, body: string, category?: string, needsPerson?: bool}> */
    #[ORM\Column(type: Types::JSON)]
    public array $findings;

    /** @var ?list<array{index: int, answer: string, reason: ?string}> null until the fix round answers */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $answers = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $answeredAt = null;

    /**
     * @param list<AgentReviewFinding> $findings
     */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Document::class)]
        public readonly Document $document,

        #[ORM\Column]
        public readonly int $versionNumber,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $summary,

        #[ORM\Column(length: 20, enumType: AgentReviewConclusion::class)]
        public readonly AgentReviewConclusion $conclusion,
        array $findings,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $workerRunId = null,

        #[ORM\Column(type: UuidType::NAME, nullable: true)]
        public readonly ?Uuid $workRequestId = null,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->findings = array_map(static fn (AgentReviewFinding $finding): array => $finding->toArray(), $findings);
    }

    /** @return list<AgentReviewFinding> */
    public function findings(): array
    {
        return array_map(AgentReviewFinding::fromArray(...), $this->findings);
    }

    /** @return list<DocumentReviewFindingAnswer> */
    public function answers(): array
    {
        return array_map(DocumentReviewFindingAnswer::fromArray(...), $this->answers ?? []);
    }

    public function isAnswered(): bool
    {
        return null !== $this->answeredAt;
    }

    /**
     * @param list<DocumentReviewFindingAnswer> $answers
     */
    public function answer(array $answers, \DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->answers = array_map(static fn (DocumentReviewFindingAnswer $answer): array => $answer->toArray(), $answers);
        $this->answeredAt = $at;
    }
}
