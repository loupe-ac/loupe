<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Messenger\EvaluateCard;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

/** Queues one evaluation per card. The Doctrine transport commits the message with the caller's transaction, so a caller may dispatch inside one. */
final readonly class EvaluationTrigger implements CardEvaluations
{
    public const string LOW_PRIORITY_TRANSPORT = 'card_sweep';

    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    /** @param list<string|Uuid> $cardIds */
    #[\Override]
    public function forCards(array $cardIds): void
    {
        foreach ($this->unique($cardIds) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId));
        }
    }

    /** @param list<string|Uuid> $cardIds */
    #[\Override]
    public function forPersonWaiting(array $cardIds): void
    {
        foreach ($this->unique($cardIds) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId, personWaiting: true), [new TransportNamesStamp([self::INTERACTIVE_TRANSPORT])]);
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
