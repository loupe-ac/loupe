<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Module\Insights\Entity\PublicLinkKind;
use App\Module\Insights\Preview\PreviewRenderFailed;
use App\Module\Insights\Preview\PreviewResultUnsettled;
use App\Module\Insights\Preview\PublicPreviewDrawer;
use App\Module\Insights\Preview\PublicPreviewEntries;
use App\Module\Insights\Preview\PublicPreviewSubjects;
use App\Module\Insights\Service\PublicLinkVisibility;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads the preview image of the link behind a code. The kept PNG answers when it shows the version of the page now.
 * Otherwise the drawer draws it. A result that never settles, a failed tool and an experiment give no image.
 */
final readonly class ShowPublicLinkPreviewHandler
{
    public function __construct(
        private PublicLinkVisibility $visibility,
        private PublicPreviewSubjects $subjects,
        private PublicPreviewEntries $entries,
        private PublicPreviewDrawer $drawer,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws NotFoundHttpException when the code shows nothing */
    public function __invoke(ShowPublicLinkPreviewCommand $command): ShowPublicLinkPreviewView
    {
        $link = $this->visibility->resolve($command->code) ?? throw new NotFoundHttpException('No public link shows for this code.');
        $subject = PublicLinkKind::Graph === $link->kind ? $this->subjects->read($link) : null;
        if (null === $subject) {
            return new ShowPublicLinkPreviewView(null, 'fallback');
        }

        $entry = $this->entries->entryFor($link->code);
        $kept = null !== $entry && $entry->version === $subject->version ? $this->entries->png($entry) : null;
        if (null !== $kept) {
            return new ShowPublicLinkPreviewView($kept, 'kept');
        }

        try {
            return new ShowPublicLinkPreviewView($this->drawer->draw($link, PublicPreviewDrawer::INLINE)->png, 'inline');
        } catch (PreviewResultUnsettled $unsettled) {
            $this->logger->info('insights.preview_unsettled', ['code' => $link->code, 'reason' => $unsettled->getMessage()]);

            return new ShowPublicLinkPreviewView(null, 'unsettled');
        } catch (PreviewRenderFailed $failed) {
            $this->logger->error('insights.preview_render_failed', ['code' => $link->code, 'error' => $failed->getMessage()]);

            return new ShowPublicLinkPreviewView(null, 'fallback');
        }
    }
}
