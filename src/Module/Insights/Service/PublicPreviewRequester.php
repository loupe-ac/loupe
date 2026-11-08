<?php

declare(strict_types=1);

namespace App\Module\Insights\Service;

use App\Module\Insights\Messenger\DrawPublicPreview;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * Asks the insights worker to draw the preview image of a link. A second request is dropped while the first one waits in
 * the queue. The stamp key differs from the lock of the drawer, because the stamp holds a lock of that name while queued.
 */
final readonly class PublicPreviewRequester
{
    /** Seconds. */
    private const int DEDUPLICATE_TTL = 300;

    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function draw(string $code): void
    {
        $this->bus->dispatch(new DrawPublicPreview($code), [new DeduplicateStamp('draw_public_preview:'.$code, self::DEDUPLICATE_TTL, true)]);
    }
}
