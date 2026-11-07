<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge;

use App\Module\Workflow\Contract\CardEvaluations;

final class RecordingCardEvaluations implements CardEvaluations
{
    /** @var list<list<string>> */
    public array $evaluated = [];

    /** @var list<list<string>> */
    public array $personWaiting = [];

    #[\Override]
    public function forPersonWaiting(array $cardIds, string $cause, ?\DateTimeImmutable $occurredAt = null): void
    {
        $this->personWaiting[] = array_map(strval(...), $cardIds);
    }

    #[\Override]
    public function forCards(array $cardIds, string $cause, ?\DateTimeImmutable $occurredAt = null): void
    {
        $this->evaluated[] = array_map(strval(...), $cardIds);
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }
}
