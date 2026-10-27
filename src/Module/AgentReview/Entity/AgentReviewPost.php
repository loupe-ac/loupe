<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Entity;

use App\Module\AgentReview\Repository\AgentReviewPostRepository;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** The check of a review on a later head whose change is identical. The review keeps the check of its own head. */
#[ORM\Entity(repositoryClass: AgentReviewPostRepository::class)]
#[ORM\Table(name: 'agent_review_posts')]
#[ORM\UniqueConstraint(name: 'uniq_agent_review_posts_review_head', columns: ['review_id', 'head_sha'])]
class AgentReviewPost
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?int $checkRunId = null;

    #[ORM\Column]
    public int $annotationsPosted = 0;

    /** When the first check of this review landed. A repost of the same check, such as for an override, keeps it. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $postedAt = null;

    /** What the check run shows. Null for a check posted before the override existed: the review conclusion then tells. */
    #[ORM\Column(length: 20, nullable: true, enumType: PullRequestCheckConclusion::class)]
    public ?PullRequestCheckConclusion $checkConclusion = null;

    /** The login of the approver that the check run names as the override, or null when it names none. */
    #[ORM\Column(nullable: true)]
    public ?string $checkApprover = null;

    /** The name the check run shows, so a renamed check is posted again. */
    #[ORM\Column(nullable: true)]
    public ?string $checkName = null;

    /** Lower-case, so one head has one row whatever case it came in. */
    #[ORM\Column(length: 64)]
    public readonly string $headSha;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: AgentReview::class)]
        public readonly AgentReview $review,
        string $headSha,

        /** Decided when the row is made, so a retry sends the same check. */
        #[ORM\Column]
        public readonly bool $lineNotes,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
        $this->headSha = mb_strtolower($headSha);
    }
}
