<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

use App\Module\Inbox\Service\DiscoveryReviewReconciler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class CancelDiscoveryReviewHandler
{
    public function __construct(
        private DiscoveryReviewReconciler $reconciler,
    ) {
    }

    public function __invoke(CancelDiscoveryReview $message): void
    {
        $this->reconciler->withdrawn(Uuid::fromString($message->projectId), Uuid::fromString($message->runId));
    }
}
