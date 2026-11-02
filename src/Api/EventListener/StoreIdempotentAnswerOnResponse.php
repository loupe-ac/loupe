<?php

declare(strict_types=1);

namespace App\Api\EventListener;

use App\Api\Idempotency\Repository\IdempotencyRecordRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * Stores the answer of a request that holds a claim, a 4xx or a 5xx included. A
 * 5xx stays, because the handler may have committed before the failure. A request
 * that dies before this listener leaves its claim running until the key ends.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class StoreIdempotentAnswerOnResponse
{
    /** Headers that a retry gets back. The budget headers are left out, so a retry reads the current window. */
    private const array REPLAYED_HEADERS = ['Content-Type', 'Location', 'Link'];

    public function __construct(
        private IdempotencyRecordRepository $idempotencyRecords,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(ClaimIdempotencyKeyOnRequest::ATTRIBUTE);
        if (!$event->isMainRequest() || !$id instanceof Uuid) {
            return;
        }

        $response = $event->getResponse();
        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        $this->idempotencyRecords->finish($id, $response->getStatusCode(), $headers, (string) $response->getContent());
        $event->getRequest()->attributes->remove(ClaimIdempotencyKeyOnRequest::ATTRIBUTE);
    }
}
