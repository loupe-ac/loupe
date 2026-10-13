<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Project\Entity\Project;

/** One project on the account inbox page. An open ask counts once, and so does an open item that no open ask holds. */
final readonly class AccountInboxRow
{
    public function __construct(
        public Project $project,
        public int $openAskCount,
        public ?\DateTimeImmutable $oldestWait,
        public bool $blocking,
    ) {
    }
}
