<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewComment;
use OpenApi\Attributes as OA;

/** A note of a feedback list with no `fields`: the facts of the note, and never its body, anchors or device facts. */
#[OA\Schema(description: 'A note of a list, without its body, its anchors and its device facts.', required: ['id', 'url', 'surface', 'screenshot', 'hasDrawing', 'status', 'context', 'createdAt', 'cardId', 'number', 'title'])]
final readonly class FeedbackRowResponse
{
    /**
     * @param ?array{width: int, height: int} $screenshot
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'The page of a web note, or null for a native note.', nullable: true)]
        public ?string $url,

        #[OA\Property(enum: ['web', 'ios-simulator'])]
        public string $surface,

        #[OA\Property(description: 'The size of the marked image of the note, or null.', required: ['width', 'height'], properties: [new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer')], type: 'object', nullable: true)]
        public ?array $screenshot,

        #[OA\Property(description: 'True when the note has strokes. Ask the reviewer rather than guess.')]
        public bool $hasDrawing,

        #[OA\Property(enum: ['pending', 'addressed', 'resolved'])]
        public string $status,

        #[OA\Property(description: 'What a preview page said it served, or null.', nullable: true)]
        public ?string $context,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(format: 'uuid', nullable: true)]
        public ?string $cardId,

        #[OA\Property(description: 'The number of its card.', nullable: true)]
        public ?int $number,

        #[OA\Property(description: 'The title of its card.', nullable: true)]
        public ?string $title,
    ) {
    }

    /** @param ?array{width: int, height: int} $screenshot */
    public static function of(SiteReviewComment $comment, ?CardSiteReviewComment $link, ?array $screenshot): self
    {
        return new self(
            id: (string) $comment->id,
            url: $comment->url,
            surface: $comment->surface->value,
            screenshot: $screenshot,
            hasDrawing: [] !== ($comment->strokes ?? []),
            status: $comment->status->value,
            context: $comment->context,
            createdAt: $comment->createdAt->format(\DATE_ATOM),
            cardId: null === $link ? null : (string) $link->card->id,
            number: $link?->card->number,
            title: $link?->card->title,
        );
    }
}
