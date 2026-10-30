<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Workflow\Contract\CardValueKeys;
use Symfony\Component\Uid\Uuid;

final readonly class ShowCardHistoryHandler
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
        private CardValueKeys $valueKeys,
    ) {
    }

    public function __invoke(ShowCardHistoryCommand $command): CardHistoryView
    {
        $events = $this->cardEvents->findPageBeforeForCard($command->card, $command->beforeAt, $command->beforeId?->toRfc4122(), self::PAGE_SIZE + 1);
        $hasOlder = \count($events) > self::PAGE_SIZE;
        $events = \array_slice($events, 0, self::PAGE_SIZE);
        $last = $hasOlder ? array_last($events) : null;

        // A run that a rule stopped has its own row, which stands for the row of its close.
        // A run that ended another way keeps the row of its close, because that row holds the real end state.
        $stopped = array_filter(array_map(static fn (CardEvent $event): ?string => CardEventKind::Stopped === $event->kind ? CardHistoryEntry::runIdOf($event) : null, $events));
        $events = array_values(array_filter($events, static fn (CardEvent $event): bool => CardEventKind::RunFinished !== $event->kind
            || WorkerRunState::Stopped->value !== ($event->detail['state'] ?? null)
            || !\in_array(CardHistoryEntry::runIdOf($event), $stopped, true)));

        $runIds = array_values(array_filter(array_map(CardHistoryEntry::runIdOf(...), $events)));
        $existing = $this->workerRuns->findExistingIds($command->card->project, array_map(Uuid::fromString(...), $runIds));

        $historyPhrases = [];
        if ([] !== array_filter($events, static fn (CardEvent $event): bool => CardEventKind::ValueChanged === $event->kind)) {
            foreach ($this->valueKeys->forProject($command->card->project->requireId()) as $key) {
                $historyPhrases[$key->key] = $key->history;
            }
        }

        return new CardHistoryView(
            $command->card,
            $command->beforeId?->toRfc4122(),
            array_map(static function (CardEvent $event) use ($existing, $historyPhrases): CardHistoryEntry {
                $runId = CardHistoryEntry::runIdOf($event);

                return CardHistoryEntry::of($event, null !== $runId && \in_array(Uuid::fromString($runId)->toRfc4122(), $existing, true), $historyPhrases);
            }, $events),
            $last?->occurredAt->format(ShowCardHistoryCommand::CURSOR_FORMAT),
            $last?->id?->toRfc4122(),
        );
    }
}
