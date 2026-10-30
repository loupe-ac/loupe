<?php

declare(strict_types=1);

namespace App\Module\Workflow\Entity;

use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** A card has an evaluation message waiting in the queue. The row lives from the trigger that queued it to the worker that receives the message. */
#[ORM\Entity(repositoryClass: WorkflowQueuedEvaluationRepository::class)]
#[ORM\Table(name: 'workflow_queued_evaluations')]
class WorkflowQueuedEvaluation
{
    public function __construct(
        /** No foreign key, as the card may go. WorkflowRowCleaner deletes the row. */
        #[ORM\Column(type: UuidType::NAME)]
        #[ORM\Id]
        public readonly Uuid $cardId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $queuedAt = new \DateTimeImmutable(),
    ) {
    }
}
