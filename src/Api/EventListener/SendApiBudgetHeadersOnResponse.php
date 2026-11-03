<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Copies the budget headers that ApplyApiBudgetOnRequest read from the limiter onto the answer. */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class SendApiBudgetHeadersOnResponse
{
    public function __invoke(ResponseEvent $event): void
    {
        $headers = $event->getRequest()->attributes->get(ApplyApiBudgetOnRequest::ATTRIBUTE);
        if (!$event->isMainRequest() || !\is_array($headers)) {
            return;
        }

        foreach ($headers as $name => $value) {
            $event->getResponse()->headers->set((string) $name, (string) $value);
        }
    }
}
