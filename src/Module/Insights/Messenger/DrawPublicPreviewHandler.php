<?php

declare(strict_types=1);

namespace App\Module\Insights\Messenger;

use App\Module\Insights\Preview\PreviewResultUnsettled;
use App\Module\Insights\Preview\PublicPreviewDrawer;
use App\Module\Insights\Preview\PublicPreviewSubjects;
use App\Module\Insights\Service\PublicLinkVisibility;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Draws and keeps the preview image of a link that shows. A hidden link and a link with no result draw
 * nothing. A tool that fails throws, so Messenger retries the draw.
 */
#[AsMessageHandler]
final readonly class DrawPublicPreviewHandler
{
    public function __construct(
        private PublicLinkVisibility $visibility,
        private PublicPreviewSubjects $subjects,
        private PublicPreviewDrawer $drawer,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DrawPublicPreview $message): void
    {
        $link = $this->visibility->resolve($message->code);
        if (null === $link || null === $this->subjects->read($link)) {
            return;
        }

        try {
            $this->drawer->draw($link, PublicPreviewDrawer::WORKER);
        } catch (PreviewResultUnsettled $unsettled) {
            $this->logger->info('insights.preview_unsettled', ['code' => $message->code, 'reason' => $unsettled->getMessage()]);
        }
    }
}
