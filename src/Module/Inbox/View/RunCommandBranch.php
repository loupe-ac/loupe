<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One branch of the branch list, with the folder the command runs in, or no folder when the bridge has no worktree for it. */
final readonly class RunCommandBranch
{
    public function __construct(
        public string $branch,
        public ?string $path,
    ) {
    }
}
