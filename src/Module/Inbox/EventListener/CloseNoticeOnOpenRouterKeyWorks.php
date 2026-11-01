<?php

declare(strict_types=1);

namespace App\Module\Inbox\EventListener;

use App\Module\Inbox\Service\OpenRouterKeyNoticeReconciler;
use App\Module\OpenRouter\Event\OpenRouterKeyWorks;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class CloseNoticeOnOpenRouterKeyWorks
{
    public function __construct(
        private OpenRouterKeyNoticeReconciler $reconciler,
    ) {
    }

    public function __invoke(OpenRouterKeyWorks $event): void
    {
        $this->reconciler->works($event->keyId);
    }
}
