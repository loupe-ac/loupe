<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\Command;

use App\Module\Bridge\Command\RecordBilledCostCommand;
use App\Module\Bridge\Command\RecordBilledCostHandler;
use App\Module\Bridge\Entity\WorkerRunBilledCost;
use App\Module\Bridge\Repository\WorkerRunBilledCostRepository;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\OpenRouter\Entity\OpenRouterKey;
use App\Module\OpenRouter\Entity\OpenRouterKeyState;
use App\Module\OpenRouter\Event\OpenRouterKeyRefused;
use App\Module\OpenRouter\Event\OpenRouterKeyWorks;
use App\Module\OpenRouter\Messenger\ReadBilledCost;
use App\Module\OpenRouter\Repository\OpenRouterKeyRepository;
use App\Module\OpenRouter\Service\BilledCostSchedule;
use App\Module\OpenRouter\Service\OpenRouterAnalytics;
use App\Module\OpenRouter\Service\OpenRouterCostOutcome;
use App\Module\OpenRouter\Service\OpenRouterCostReading;
use App\Module\OpenRouter\Service\OpenRouterKeyChangedPublisher;
use App\Module\OpenRouter\Service\OpenRouterKeyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * One read of the billed cost of a run. It loads the billed cost row, never
 * the run, and asks for the session of the row, which is the run key. A cost
 * counts when two reads in a row return the same total. Every path either
 * records a final state or schedules the next read, and the schedule ends, so
 * the reads of a run end too.
 */
final readonly class ReadBilledCostHandler
{
    public function __construct(
        private WorkerRunBilledCostRepository $workerRunBilledCosts,
        private OpenRouterKeyResolver $keyResolver,
        private OpenRouterKeyRepository $openRouterKeys,
        private OpenRouterAnalytics $analytics,
        private RecordBilledCostHandler $recordBilledCost,
        private OpenRouterKeyChangedPublisher $keyChanged,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReadBilledCostCommand $command): void
    {
        $row = $this->workerRunBilledCosts->find($command->runId);
        if (null === $row || $row->state->isFinal()) {
            return;
        }

        $summary = $this->keyResolver->resolve($row->project);
        $key = null === $summary ? null : $this->openRouterKeys->find($summary->id);
        if (null === $key) {
            $this->recordUnknown($row, BilledCostReason::NoKey);

            return;
        }

        $reading = $this->analytics->totalCostForSession($key->secret, (string) $row->runKey, $row->runStartedAt);
        $this->recordKeyHealth($key, $row, $reading);

        $read = $row->attempts;
        $last = BilledCostSchedule::isLast($read);
        match ($reading->outcome) {
            OpenRouterCostOutcome::Refused => $this->recordUnknown($row, BilledCostReason::KeyRefused),
            OpenRouterCostOutcome::Total => $this->settle($row, $reading->costUsd ?? throw new \LogicException('A total carries its cost.'), $last),
            OpenRouterCostOutcome::NoCost => $last && null === $row->firstReadCost
                ? $this->recordUnknown($row, BilledCostReason::NoCostFound, $read + 1)
                : $this->again($row, $row->firstReadCost, $last),
            OpenRouterCostOutcome::NoAnswer => $this->again($row, $row->firstReadCost, $last),
        };
    }

    /** Two equal totals in a row settle the cost. A different total replaces the first and waits one more read. */
    private function settle(WorkerRunBilledCost $row, string $total, bool $last): void
    {
        if (null !== $row->firstReadCost && $row->firstReadCost === $total) {
            ($this->recordBilledCost)(new RecordBilledCostCommand($row->runId, BilledCostState::Billed, costUsd: $total, attempts: $row->attempts + 1));

            return;
        }

        $this->again($row, $total, $last);
    }

    /** Waits for the next read, or gives up when the schedule has no more. */
    private function again(WorkerRunBilledCost $row, ?string $firstReadCost, bool $last): void
    {
        if ($last) {
            $this->recordUnknown($row, BilledCostReason::NoAnswer, $row->attempts + 1);

            return;
        }

        ($this->recordBilledCost)(new RecordBilledCostCommand($row->runId, BilledCostState::Pending, firstReadCost: $firstReadCost, attempts: $row->attempts + 1));
        $this->bus->dispatch(
            new ReadBilledCost((string) $row->runId),
            [new DelayStamp(BilledCostSchedule::delaySeconds($row->attempts) * 1000)],
        );
    }

    private function recordUnknown(WorkerRunBilledCost $row, BilledCostReason $reason, ?int $attempts = null): void
    {
        ($this->recordBilledCost)(new RecordBilledCostCommand($row->runId, BilledCostState::Unknown, $reason, attempts: $attempts ?? $row->attempts));
    }

    /** A 401 or a 403 refuses the key. An answer that came back marks a refused key as working again. */
    private function recordKeyHealth(OpenRouterKey $key, WorkerRunBilledCost $row, OpenRouterCostReading $reading): void
    {
        $keyId = $key->id ?? throw new \LogicException('A stored key has an id.');
        $projectId = $row->project->requireId();
        $forProject = null !== $key->project;

        if (OpenRouterCostOutcome::Refused === $reading->outcome) {
            if (OpenRouterKeyState::Working === $key->state) {
                $key->state = OpenRouterKeyState::Refused;
                $key->refusedSince = $this->clock->now();
            }
            $this->em->flush();
            $this->keyChanged->changed($key->project ?? $row->project->owner);
            $this->events->dispatch(new OpenRouterKeyRefused($keyId, $forProject, $projectId));

            return;
        }

        if (!$reading->outcome->keyWorked()) {
            return;
        }

        $changed = OpenRouterKeyState::Refused === $key->state;
        $key->state = OpenRouterKeyState::Working;
        $key->refusedSince = null;
        $key->lastReadAt = $this->clock->now();
        $this->em->flush();
        if ($changed) {
            $this->keyChanged->changed($key->project ?? $row->project->owner);
            $this->events->dispatch(new OpenRouterKeyWorks($keyId, $forProject, $projectId));
        }
    }
}
