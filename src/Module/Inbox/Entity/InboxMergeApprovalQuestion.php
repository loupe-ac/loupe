<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Inbox\Repository\InboxMergeApprovalQuestionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Ties the merge question of one repository to the stretch of time in which the repository has no answer.
 * The repository id has no foreign key, because Inbox maps no Forge entity. The row outlives the answer of
 * the owner until `.loupe.yaml` states the setting, so the same case opens no second question.
 */
#[ORM\Entity(repositoryClass: InboxMergeApprovalQuestionRepository::class)]
#[ORM\Table(name: 'inbox_merge_approval_questions')]
// One open question per repository. The predicate is written the way Postgres stores it.
#[ORM\UniqueConstraint(name: 'uniq_inbox_merge_approval_questions_open_repository', columns: ['repository_id'], options: ['where' => '(ended_at IS NULL)'])]
class InboxMergeApprovalQuestion
{
    /** The options of the question, in the order the inbox shows them. The index of an answer picks the value. */
    public const array VALUES = ['agent', 'person'];

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $endedAt = null;

    /** The value the owner picked, once the owner answers. */
    #[ORM\Column(length: 10, nullable: true)]
    public ?string $answer = null;

    /** The address of the pull request that writes the answer into `.loupe.yaml`, once Loupe opened it. */
    #[ORM\Column(length: 512, nullable: true)]
    public ?string $pullRequestUrl = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\Column(type: UuidType::NAME)]
        public readonly Uuid $repositoryId,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\OneToOne(targetEntity: InboxItem::class)]
        public readonly InboxItem $item,

        #[ORM\Column]
        public readonly \DateTimeImmutable $startedAt,
    ) {
        if (InboxItemKind::Question !== $item->kind) {
            throw new \InvalidArgumentException('A merge approval question belongs to a question item.');
        }
    }
}
