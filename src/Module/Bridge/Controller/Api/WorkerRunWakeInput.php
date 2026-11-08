<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunWakeReport;
use App\Module\Bridge\ValueObject\WorkerRunWakeTrigger;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A resume as the bridge reports it. A null time or null first turn is unknown, such as
 * from a harness that gives none.
 */
class WorkerRunWakeInput
{
    public function __construct(
        #[Assert\Choice(callback: 'triggers')]
        #[Assert\NotBlank]
        public ?string $trigger = null,
        public ?\DateTimeImmutable $lastTurnAt = null,

        #[Assert\Valid]
        public ?WorkerRunTurnUsageInput $firstTurn = null,
    ) {
    }

    /** @return list<string> */
    public static function triggers(): array
    {
        return array_map(static fn (WorkerRunWakeTrigger $trigger): string => $trigger->value, WorkerRunWakeTrigger::cases());
    }

    public function report(): WorkerRunWakeReport
    {
        return new WorkerRunWakeReport(
            WorkerRunWakeTrigger::from($this->trigger ?? throw new \LogicException('trigger is required after validation.')),
            null === $this->lastTurnAt ? null : $this->lastTurnAt->setTimezone(new \DateTimeZone('UTC')),
            $this->firstTurn?->inputTokens,
            $this->firstTurn?->cacheReadTokens,
            $this->firstTurn?->cacheWriteTokens,
        );
    }
}
