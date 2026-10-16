<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

/** One branch that has a worktree on the bridge, with the folder the command runs in. */
final readonly class RunCommandBranch
{
    public function __construct(
        public string $branch,
        public string $path,
    ) {
    }
}
