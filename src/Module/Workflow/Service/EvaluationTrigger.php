<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Install\WorkflowInstallFlags;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowQueuedEvaluationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\FeatureFlagService;

/** Queues one evaluation per card. The Doctrine transport commits the message with the caller's transaction, so a caller may dispatch inside one. */
final readonly class EvaluationTrigger implements CardEvaluations
{
    public const string LOW_PRIORITY_TRANSPORT = 'card_sweep';

    public function __construct(
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
        private WorkflowQueuedEvaluationRepository $workflowQueuedEvaluations,
        private FeatureFlagService $featureFlags,
        private ClockInterface $clock,
    ) {
    }

    /**
     * With the merge flag on, a card that has a message waiting gets no second one, because the waiting message reads the card when the worker takes it.
     * The claim and the message commit together, so a rolled-back caller leaves neither.
     *
     * @param list<string|Uuid> $cardIds
     */
    #[\Override]
    public function forCards(array $cardIds): void
    {
        $merge = $this->featureFlags->isEnabled(WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS);
        foreach ($this->unique($cardIds) as $cardId) {
            if (!$merge) {
                $this->bus->dispatch(new EvaluateCard($cardId));

                continue;
            }

            $this->em->wrapInTransaction(function () use ($cardId): void {
                if ($this->workflowQueuedEvaluations->claim(Uuid::fromString($cardId), \DateTimeImmutable::createFromInterface($this->clock->now()))) {
                    $this->bus->dispatch(new EvaluateCard($cardId));
                }
            });
        }
    }

    /**
     * Queues each evaluation on the low-priority transport, which the worker reads only when the other transports are empty.
     *
     * @param list<string|Uuid> $cardIds
     */
    public function inBackground(array $cardIds): void
    {
        foreach ($this->unique($cardIds) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId), [new TransportNamesStamp([self::LOW_PRIORITY_TRANSPORT])]);
        }
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    /**
     * @param list<string|Uuid> $cardIds
     *
     * @return list<string>
     */
    private function unique(array $cardIds): array
    {
        $unique = [];
        foreach ($cardIds as $cardId) {
            $unique[($cardId instanceof Uuid ? $cardId : Uuid::fromString($cardId))->toRfc4122()] = true;
        }

        return array_keys($unique);
    }
}
