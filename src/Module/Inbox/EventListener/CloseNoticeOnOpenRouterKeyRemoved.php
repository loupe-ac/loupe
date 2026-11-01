<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\OpenRouterKeyNoticeReconciler;
use App\Module\OpenRouter\Event\OpenRouterKeyRemoved;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class CloseNoticeOnOpenRouterKeyRemoved
{
    public function __construct(
        private OpenRouterKeyNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(OpenRouterKeyRemoved $event): void
    {
        $this->reconciler->removed($event->keyId);
    }
}
