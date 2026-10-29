<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunFact;
use Symfony\Component\Uid\Uuid;

/** One worker run of a card, read from its run row or from its fact. The report merges both sources by run id. */
final readonly class CardRun
{
    public function __construct(
        public Uuid $runId,
        public ?int $cardNumber,
        public ?string $workKind,
        public ?string $experiment,
        public ?string $variant,
        public \DateTimeImmutable $receivedAt,
    ) {
    }

    public static function fromRun(WorkerRun $run): self
    {
        return new self($run->id ?? throw new \LogicException('A stored run has an id.'), $run->cardNumber, $run->workKind, $run->experiment, $run->variant, $run->receivedAt);
    }

    public static function fromFact(WorkerRunFact $fact): self
    {
        return new self($fact->runId, $fact->cardNumber, $fact->workKind, $fact->experiment, $fact->variant, $fact->receivedAt);
    }

    public function isOf(string $experiment): bool
    {
        return $experiment === $this->experiment;
    }

    /** @param list<string> $kinds */
    public function isOfKind(array $kinds): bool
    {
        return null !== $this->workKind && \in_array($this->workKind, $kinds, true);
    }
}
