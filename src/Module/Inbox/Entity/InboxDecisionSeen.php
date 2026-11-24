<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Doctrine\Type\MicrosecondDateTimeImmutableType;
use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Inbox\Repository\InboxDecisionSeenRepository;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The time a person last opened the Decisions tab of a card. The inbox digest counts the decisions
 * newer than it. The project column lets the project deletion remove the rows of every viewer.
 */
#[ORM\Entity(repositoryClass: InboxDecisionSeenRepository::class)]
#[ORM\Table(name: 'inbox_decision_seen')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_decision_seen_user_card', columns: ['user_id', 'card_id'])]
class InboxDecisionSeen
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

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public readonly User $user,

        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column(type: MicrosecondDateTimeImmutableType::NAME, columnDefinition: 'TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL')]
        public \DateTimeImmutable $seenAt = new \DateTimeImmutable(),
    ) {
    }
}
