<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

final readonly class GitHubPickerSignIn
{
    public function __construct(
        /** The path that a fresh install added at once, or null when the picker opens. */
        public ?string $connected,
        /** @var list<string> the paths that the fresh install could not add because another project took them first */
        public array $refused = [],
    ) {
    }
}
