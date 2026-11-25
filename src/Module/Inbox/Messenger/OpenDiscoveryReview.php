<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

final readonly class OpenDiscoveryReview
{
    public function __construct(
        public string $projectId,
        public string $runId,
        public string $documentId,
        public int $versionNumber,
    ) {
    }
}
