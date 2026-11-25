<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

final readonly class CancelDiscoveryReview
{
    public function __construct(
        public string $projectId,
        public string $runId,
    ) {
    }
}
