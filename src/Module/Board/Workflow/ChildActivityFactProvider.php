<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\Uid\Uuid;

final readonly class ChildActivityFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardEventRepository $cardEvents,
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ChildActivityFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $childIds = $this->cards->findChildIds($card->id);
        if ([] === $childIds) {
            return new ChildActivityFacts(null, false);
        }

        $newest = null;
        foreach ($this->cardEvents->findNewestOccurredAtOfCards($childIds) as $at) {
            if (null === $newest || $at > $newest) {
                $newest = $at;
            }
        }

        return new ChildActivityFacts($newest, $this->workerRuns->anyOpenWorkerRunOfCards(array_map(Uuid::fromString(...), $childIds)));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ChildActivityFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return [$facts->newestEventAt?->format(\DATE_ATOM), $facts->runOpen];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
