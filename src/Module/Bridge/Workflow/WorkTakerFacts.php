<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Bridge\ValueObject\WorkTakerReading;
use App\Module\Bridge\ValueObject\WorkTakerState;
use App\Module\Workflow\Contract\TimedFacts;

/** Whether an online bridge could take each open work request of the card. A claimed request has no reading. */
final readonly class WorkTakerFacts implements TimedFacts
{
    /** @param array<string, WorkTakerReading> $readings kind => the reading of its open request */
    public function __construct(
        public array $readings,
    ) {
    }

    /** The reading that has no taker, online but incapable bridges included, and has waited longest, or null when every open request has one. */
    public function withoutTaker(): ?WorkTakerReading
    {
        return $this->earliest(WorkTakerState::None, WorkTakerState::NotCapable);
    }

    /** The reading of a request that an online bridge follows but none can run, or null when no request is in that state. */
    public function notCapable(): ?WorkTakerReading
    {
        return $this->earliest(WorkTakerState::NotCapable);
    }

    /** The reading of a request whose takers are all paused, or null while another request has no taker at all. */
    public function onlyPaused(): ?WorkTakerReading
    {
        return null === $this->withoutTaker() ? $this->earliest(WorkTakerState::Paused) : null;
    }

    /** The reading of a request that waits for a usage limit, or null while another request has no taker at all. */
    public function limited(): ?WorkTakerReading
    {
        return null === $this->withoutTaker() ? $this->earliest(WorkTakerState::Limited) : null;
    }

    /** The reading of a request whose bridge lost its network, or null while another request has no taker at all. */
    public function disconnected(): ?WorkTakerReading
    {
        return null === $this->withoutTaker() ? $this->earliest(WorkTakerState::Disconnected) : null;
    }

    /** The first time after now that a reading changes by the clock alone: its online bridge turns quiet, an account resets, a claim ends or a network wait ends. A time at or before now is already in the answer, so a later time of another request still counts. */
    #[\Override]
    public function changesAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $times = array_filter(
            array_map(static fn (WorkTakerReading $reading): ?\DateTimeImmutable => $reading->quietAt, $this->readings),
            static fn (?\DateTimeImmutable $time): bool => null !== $time && $time > $now,
        );

        return [] === $times ? null : min($times);
    }

    private function earliest(WorkTakerState ...$states): ?WorkTakerReading
    {
        $matching = array_filter($this->readings, static fn (WorkTakerReading $reading): bool => \in_array($reading->state, $states, true));
        usort($matching, static fn (WorkTakerReading $a, WorkTakerReading $b): int => $a->noTakerSince <=> $b->noTakerSince);

        return $matching[0] ?? null;
    }
}
