<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Repository\CardValueRepository;
use App\Module\Workflow\Contract\Actor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The value a card holds for one key of its workflow template.
 *
 * The row holds no type. A reader checks the value against the type the template
 * declares now, and a row that does not match, or whose key the template no longer
 * declares, reads as unset.
 */
#[ORM\Entity(repositoryClass: CardValueRepository::class)]
#[ORM\Table(name: 'board_card_values')]
#[ORM\UniqueConstraint(name: 'uniq_board_card_values_card_key', columns: ['card_id', 'key'])]
class CardValue
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Id]
    public private(set) ?Uuid $id = null;

    public function __construct(
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ORM\ManyToOne(targetEntity: Card::class)]
        public readonly Card $card,

        #[ORM\Column(length: 64)]
        public readonly string $key,

        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        public mixed $value,

        #[ORM\Column(length: 20, enumType: Actor::class)]
        public Actor $writerKind,

        /** The person behind a human or agent write. A deleted account leaves the row with no one. */
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        #[ORM\ManyToOne(targetEntity: User::class)]
        public ?User $writerUser = null,

        #[ORM\Column]
        public \DateTimeImmutable $updatedAt = new \DateTimeImmutable(),
    ) {
    }
}
