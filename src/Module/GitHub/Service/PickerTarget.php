<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** A route and its parameters, the place a return of the picker goes to. */
final readonly class PickerTarget
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public string $route,
        public array $parameters,
    ) {
    }

    public function isHandoff(): bool
    {
        return 'app_github_init_handoff' === $this->route;
    }
}
