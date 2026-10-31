<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\OpenRouterKeyNoticeReconciler;
use App\Module\OpenRouter\Event\OpenRouterKeyRefused;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class OpenNoticeOnOpenRouterKeyRefused
{
    public function __construct(
        private OpenRouterKeyNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(OpenRouterKeyRefused $event): void
    {
        $this->reconciler->refused($event->keyId, $event->forProject, $event->projectId);
    }
}
