<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A new history row of a child can change the newest child event, and with it the
 * "nothing happened" marker of its epic. Inside wrapInTransaction the queued
 * evaluation commits with the row. A flush that throws never reaches postFlush,
 * so a clear or a service reset drops the ids it collected. A row written through
 * DBAL skips this listener.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class EvaluateParentsOnCardEventWritten implements ResetInterface
{
    /** @var array<string, true> card ids */
    public private(set) array $cardIds = [];

    public function __construct(
        private readonly CardRepository $cards,
        private readonly CardEvaluations $trigger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CardEvent && null !== $entity->card->id) {
                $this->cardIds[$entity->card->id->toRfc4122()] = true;
            }
        }
    }

    public function postFlush(): void
    {
        if ([] === $this->cardIds) {
            return;
        }

        $cardIds = array_keys($this->cardIds);
        $this->cardIds = [];
        if ($this->trigger->isOn()) {
            $this->trigger->forCards($this->cards->findParentIds($cardIds));
        }
    }

    public function onClear(): void
    {
        $this->reset();
    }

    #[\Override]
    public function reset(): void
    {
        $this->cardIds = [];
    }
}
