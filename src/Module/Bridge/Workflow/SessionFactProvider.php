<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;

final readonly class SessionFactProvider implements FactProvider
{
    public function __construct(
        private WorkerRunRepository $workerRuns,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return SessionFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $row = $this->workerRuns->findOldestOpenInteractiveRowOfCard($card->id);

        return null === $row ? new SessionFacts(null, null) : new SessionFacts(new \DateTimeImmutable($row['since']), $row['work_kind']);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof SessionFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return null !== $facts->openSince;
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.bridge';
    }
}
