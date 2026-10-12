<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Project\Entity\Project;

/** One project on the account inbox page. */
final readonly class AccountInboxRow
{
    public function __construct(
        public Project $project,
        public int $openItemCount,
        public ?\DateTimeImmutable $oldestWait,
        public bool $blocking,
    ) {
    }
}
