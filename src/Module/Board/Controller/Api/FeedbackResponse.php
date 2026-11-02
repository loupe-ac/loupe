<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Api\OpenApi\FieldsSchemaProcessor;
use App\Module\Board\Command\ShowFeedbackView;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentAnchor;
use OpenApi\Attributes as OA;

/** A note that a reviewer left with the live-review widget, as the public API shows it. The marked image is never in it. */
#[OA\Schema(description: 'A note that a reviewer left with the live-review widget.', required: ['id', 'url', 'surface', 'native', 'screenshot', 'anchors', 'body', 'hasDrawing', 'status', 'context', 'createdAt', 'cardId', 'number', 'title'], x: [FieldsSchemaProcessor::MARKER => self::PARTIAL])]
final readonly class FeedbackResponse
{
    /** The name of the partial component of this resource. */
    public const string PARTIAL = 'PartialFeedbackItem';

    /** The top-level names of a note, in the order of its row. */
    public const array FIELDS = ['id', 'url', 'surface', 'native', 'screenshot', 'anchors', 'body', 'hasDrawing', 'status', 'context', 'createdAt', 'cardId', 'number', 'title'];

    /** The names whose value a list row leaves out. */
    public const array LEFT_OUT_OF_ROW = ['native', 'anchors', 'body'];

    /**
     * @param list<FeedbackAnchorResponse>    $anchors
     * @param ?array<string, mixed>           $native
     * @param ?array{width: int, height: int} $screenshot
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'The page of a web note, or null for a native note.', nullable: true)]
        public ?string $url,

        #[OA\Property(enum: ['web', 'ios-simulator'])]
        public string $surface,

        #[OA\Property(type: 'object', nullable: true, allOf: [new OA\Schema(ref: '#/components/schemas/NativeFacts')])]
        public ?array $native,

        #[OA\Property(description: 'The size of the marked image of the note, or null.', required: ['width', 'height'], properties: [new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer')], type: 'object', nullable: true)]
        public ?array $screenshot,

        #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/FeedbackAnchor'))]
        public array $anchors,
        public string $body,

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

    public static function fromView(ShowFeedbackView $view): self
    {
        return self::of($view->feedback, $view->link, $view->screenshot);
    }

    /** @param ?array{width: int, height: int} $screenshot */
    public static function of(SiteReviewComment $comment, ?CardSiteReviewComment $link, ?array $screenshot): self
    {
        return new self(
            id: (string) $comment->id,
            url: $comment->url,
            surface: $comment->surface->value,
            native: $comment->native,
            screenshot: $screenshot,
            anchors: array_values(array_map(
                static fn (SiteReviewCommentAnchor $anchor): FeedbackAnchorResponse => new FeedbackAnchorResponse($anchor->selector, $anchor->text, $anchor->quote, $anchor->quotePrefix, $anchor->quoteSuffix),
                $comment->anchors->toArray(),
            )),
            body: $comment->body,
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
