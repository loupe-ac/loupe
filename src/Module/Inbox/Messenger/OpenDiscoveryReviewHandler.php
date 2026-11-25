<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Service\DiscoveryReviewReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class OpenDiscoveryReviewHandler
{
    public function __construct(
        private DiscoveryReviewReconciler $reconciler,
    ) {
    }

    public function __invoke(OpenDiscoveryReview $message): void
    {
        $this->reconciler->waiting(Uuid::fromString($message->projectId), Uuid::fromString($message->runId), Uuid::fromString($message->documentId), $message->versionNumber);
    }
}
