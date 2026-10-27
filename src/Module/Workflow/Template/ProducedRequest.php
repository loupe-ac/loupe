<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** One request call of a template: the kind and role it writes, and the reasons it can write, or null for a reason the app picks at run time. */
final readonly class ProducedRequest
{
    /** @param ?list<string> $reasons */
    public function __construct(
        public string $kind,
        public ?string $role,
        public ?array $reasons,
    ) {
    }

    public function matches(?string $role, ?string $kind, ?string $reason): bool
    {
        return (null === $kind || $kind === $this->kind)
            && (null === $role || $role === $this->role)
            && (null === $reason || null === $this->reasons || \in_array($reason, $this->reasons, true));
    }
}
