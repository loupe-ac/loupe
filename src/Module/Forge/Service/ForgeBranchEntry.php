<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/**
 * The stored answer about one branch. Every time is a Unix time with microseconds.
 * An entry with no answer only records that someone asked for the branch.
 */
final readonly class ForgeBranchEntry
{
    public function __construct(
        public ?bool $exists = null,
        public ?float $seenAt = null,
        public ?float $checkedAt = null,
        public ?float $checkRequestedAt = null,
    ) {
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $time = static fn (mixed $value): ?float => \is_float($value) || \is_int($value) ? (float) $value : null;

        return new self(
            \is_bool($stored['exists'] ?? null) ? $stored['exists'] : null,
            $time($stored['seenAt'] ?? null),
            $time($stored['checkedAt'] ?? null),
            $time($stored['checkRequestedAt'] ?? null),
        );
    }

    /** @return array{exists: ?bool, seenAt: ?float, checkedAt: ?float, checkRequestedAt: ?float} */
    public function toArray(): array
    {
        return ['exists' => $this->exists, 'seenAt' => $this->seenAt, 'checkedAt' => $this->checkedAt, 'checkRequestedAt' => $this->checkRequestedAt];
    }

    /** The answer, or null when there is none or it was seen before the last access change. */
    public function answer(?float $accessMark): ?bool
    {
        if (null === $this->exists || null === $this->seenAt || (null !== $accessMark && $this->seenAt < $accessMark)) {
            return null;
        }

        return $this->exists;
    }

    public function withAnswer(bool $exists, float $seenAt, float $checkedAt): self
    {
        return new self($exists, $seenAt, $checkedAt, $this->checkRequestedAt);
    }

    public function withCheckRequestedAt(float $at): self
    {
        return new self($this->exists, $this->seenAt, $this->checkedAt, $at);
    }
}
