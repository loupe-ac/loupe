<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewScreenshotRepository;
use Mcp\Schema\Content\ImageContent;

/**
 * Picks the screenshots an MCP answer carries as images. Only open notes
 * count, newest first, so card_get and feedback_list agree on the order and
 * an offset reaches every one.
 */
final readonly class FeedbackScreenshots
{
    public const int PAGE_SIZE = 10;

    public function __construct(
        private SiteReviewScreenshotRepository $siteReviewScreenshots,
    ) {
    }

    /** @param list<SiteReviewComment> $comments */
    public function page(array $comments, int $offset = 0): FeedbackImagePage
    {
        $open = array_values(array_filter($comments, static fn (SiteReviewComment $comment): bool => SiteReviewCommentStatus::Pending === $comment->status));
        usort($open, static fn (SiteReviewComment $a, SiteReviewComment $b): int => [$b->createdAt, $b->position] <=> [$a->createdAt, $a->position]);

        $sizes = $this->siteReviewScreenshots->findSizesByComments($open);
        $withImage = array_values(array_filter($open, static fn (SiteReviewComment $comment): bool => isset($sizes[(string) $comment->id])));
        $slice = \array_slice($withImage, $offset, self::PAGE_SIZE);
        $screenshots = $this->siteReviewScreenshots->findByComments($slice);

        return new FeedbackImagePage(
            array_map(
                static function (SiteReviewComment $comment) use ($screenshots): ImageContent {
                    $screenshot = $screenshots[(string) $comment->id];

                    return new ImageContent(base64_encode($screenshot->contents()), $screenshot->contentType);
                },
                $slice,
            ),
            array_map(static fn (SiteReviewComment $comment): string => (string) $comment->id, $slice),
            max(0, \count($withImage) - $offset - \count($slice)),
        );
    }
}
