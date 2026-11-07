<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Messenger\Stamp\CauseStamp;
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
    public function forCards(array $cardIds, string $cause, ?\DateTimeImmutable $occurredAt = null): void
    {
        $merge = $this->featureFlags->isEnabled(WorkflowInstallFlags::FLAG_MERGE_QUEUED_EVALUATIONS);
        $stamp = $this->stampFor($cause, $occurredAt);
        $counts = ['queued' => 0, 'merged' => 0];
        foreach ($this->unique($cardIds) as $cardId) {
            if (!$merge) {
                $this->bus->dispatch(new EvaluateCard($cardId), [$stamp]);
                ++$counts['queued'];

                continue;
            }

            $queued = $this->em->wrapInTransaction(function () use ($cardId, $stamp): bool {
                if (!$this->workflowQueuedEvaluations->claim(Uuid::fromString($cardId), \DateTimeImmutable::createFromInterface($this->clock->now()))) {
                    return false;
                }
                $this->bus->dispatch(new EvaluateCard($cardId), [$stamp]);

                return true;
            });
            ++$counts[$queued ? 'queued' : 'merged'];
        }

        $this->count($counts, $stamp, 'card_eval');
    }

    /** @param list<string|Uuid> $cardIds */
    #[\Override]
    public function forPersonWaiting(array $cardIds, string $cause, ?\DateTimeImmutable $occurredAt = null): void
    {
        $stamp = $this->stampFor($cause, $occurredAt);
        $queued = 0;
        foreach ($this->unique($cardIds) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId, personWaiting: true), [$stamp, new TransportNamesStamp([self::INTERACTIVE_TRANSPORT])]);
            ++$queued;
        }

        $this->count(['queued' => $queued], $stamp, self::INTERACTIVE_TRANSPORT);
    }

    /**
     * Queues each evaluation on the low-priority transport, which the worker reads only when the other transports are empty.
     *
     * @param list<string|Uuid> $cardIds
     */
    public function inBackground(array $cardIds, string $cause, ?\DateTimeImmutable $occurredAt = null): void
    {
        $stamp = $this->stampFor($cause, $occurredAt);
        $queued = 0;
        foreach ($this->unique($cardIds) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId), [$stamp, new TransportNamesStamp([self::LOW_PRIORITY_TRANSPORT])]);
            ++$queued;
        }

        $this->count(['queued' => $queued], $stamp, self::LOW_PRIORITY_TRANSPORT);
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

    private function stampFor(string $cause, ?\DateTimeImmutable $occurredAt): CauseStamp
    {
        $short = strrpos($cause, '\\');

        return new CauseStamp(false === $short ? $cause : substr($cause, $short + 1), $occurredAt ?? \DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    /** @param array<string, int> $counts the cards of one call by outcome */
    private function count(array $counts, CauseStamp $stamp, string $transport): void
    {
        foreach ($counts as $outcome => $count) {
            if ($count > 0) {
                \Sentry\traceMetrics()->count('workflow.eval.enqueued', $count, ['cause' => $stamp->cause, 'transport' => $transport, 'outcome' => $outcome]);
            }
        }
    }
}
