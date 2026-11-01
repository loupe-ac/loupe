<?php

declare(strict_types=1);

namespace App\Module\OpenRouter\EventListener;

use App\Module\Bridge\Command\RecordBilledCostCommand;
use App\Module\Bridge\Command\RecordBilledCostHandler;
use App\Module\Bridge\Event\BilledCostAwaited;
use App\Module\Bridge\ValueObject\BilledCostReason;
use App\Module\Bridge\ValueObject\BilledCostState;
use App\Module\OpenRouter\Messenger\ReadBilledCost;
use App\Module\OpenRouter\Service\BilledCostSchedule;
use App\Module\OpenRouter\Service\OpenRouterKeyResolver;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Runs inside the run report request, and makes no outbound call. A project
 * with no key records its unknown cost at once, so the run never shows pending.
 */
#[AsEventListener]
final readonly class ReadBilledCostOnAwaited
{
    public function __construct(
        private ProjectRepository $projects,
        private OpenRouterKeyResolver $keyResolver,
        private RecordBilledCostHandler $recordBilledCost,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(BilledCostAwaited $event): void
    {
        $project = $this->projects->find($event->projectId);
        if (null === $project) {
            return;
        }

        if (null === $this->keyResolver->resolve($project)) {
            ($this->recordBilledCost)(new RecordBilledCostCommand($event->runId, BilledCostState::Unknown, BilledCostReason::NoKey));

            return;
        }

        $this->bus->dispatch(
            new ReadBilledCost((string) $event->runId),
            [new DelayStamp(BilledCostSchedule::delaySeconds(0) * 1000)],
        );
    }
}
