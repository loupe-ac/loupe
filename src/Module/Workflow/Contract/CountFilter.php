<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What a count or a rate picks from the work requests of a card: a role, a kind, a reason, alone or mixed. */
final readonly class CountFilter
{
    public const array FIELDS = ['role', 'kind', 'reason'];

    public function __construct(
        public ?string $role = null,
        public ?string $kind = null,
        public ?string $reason = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->role && null === $this->kind && null === $this->reason;
    }
}
