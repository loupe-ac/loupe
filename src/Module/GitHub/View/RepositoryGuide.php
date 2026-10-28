<?php

declare(strict_types=1);

namespace App\Module\GitHub\View;

/** What the row of a repository tells the owner to do on GitHub. */
final readonly class RepositoryGuide
{
    /** @param list<GuideStep> $steps */
    public function __construct(
        public array $steps,
        public ?GuideLink $classicProtectionLink = null,
    ) {
    }

    public function hasSteps(): bool
    {
        return [] !== $this->steps;
    }
}
