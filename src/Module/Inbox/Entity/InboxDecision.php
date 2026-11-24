<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Repository\InboxDecisionRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One choice an agent made on a card: logged by the template, or defaulted after a deadline.
 * The owner can change it. A change stays open until the worker marks it addressed.
 */
#[ORM\Entity(repositoryClass: InboxDecisionRepository::class)]
#[ORM\Index(name: 'idx_inbox_decisions_card_created', columns: ['card_id', 'created_at'])]
#[ORM\Table(name: 'inbox_decisions')]
class InboxDecision
{
    public const string VERSION_FORMAT = 'Y-m-d\TH:i:s.uP';

    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    /** The item a defaulted choice answered. A logged choice has none. */
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: InboxItem::class)]
    public ?InboxItem $item = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $changedChoice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $changedNote = null;

    /** UTC, with microseconds, so two changes within one second keep distinct versions. */
    #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, nullable: true, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE DEFAULT NULL')]
    public ?\DateTimeImmutable $changedAt = null;

    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    public ?User $changedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $addressedAt = null;

    /** The run that marked the change addressed. The run row may be gone later, so it is no foreign key. */
    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    public ?Uuid $addressedRunId = null;

    /** @param list<string> $otherOptions the options the agent did not take */
    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Project::class)]
        public readonly Project $project,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column(length: 40)]
        public readonly string $label,

        #[ORM\Column(type: Types::TEXT)]
        public readonly string $choice,

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        public readonly array $otherOptions,

        #[ORM\Column(length: 20, enumType: InboxDecisionMadeBy::class)]
        public InboxDecisionMadeBy $madeBy,

        #[ORM\Column]
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
    ) {
    }

    /** The change time with microseconds. decision_list gives it, and decision_mark_addressed hands it back. */
    public function changeVersion(): string
    {
        return ($this->changedAt ?? throw new \LogicException('An open change has a time.'))->format(self::VERSION_FORMAT);
    }

    /** True while a change waits for the worker to act on it. */
    public function hasOpenChange(): bool
    {
        return null !== $this->changedAt && null === $this->addressedAt;
    }
}
