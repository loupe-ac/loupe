<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A fact of the card changed since its last check. A database trigger writes the row, and the check deletes it. */
#[ORM\Entity(repositoryClass: WorkflowCardChangeRepository::class)]
#[ORM\Table(name: 'workflow_card_changes')]
class WorkflowCardChange
{
    public function __construct(
        /** No foreign key, as the card may go. WorkflowRowCleaner deletes the row. */
        #[ORM\Column(name: 'card_id', type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $cardId,

        #[ORM\Column(name: 'changed_at')]
        public \DateTimeImmutable $changedAt = new \DateTimeImmutable(),
    ) {
    }
}
