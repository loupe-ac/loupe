<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['id', 'quote', 'body', 'replacement', 'author', 'status', 'orphaned', 'thread'])]
final readonly class ReviewCommentResponse
{
    /** @param list<ReviewReplyResponse> $thread */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'Widened to whole words, except on a comment with a replacement, where it is the verbatim span.')]
        public string $quote,

        #[OA\Property]
        public string $body,

        #[OA\Property(description: 'What the reviewer wants the quote to become. Null means no edit, and an empty string means delete the passage.', nullable: true)]
        public ?string $replacement,

        #[OA\Property(enum: ['agent', 'human'])]
        public string $author,

        #[OA\Property(description: 'The status of the thread.', enum: ['pending', 'addressed', 'resolved'])]
        public string $status,

        #[OA\Property(description: 'The quote no longer appears in the document.')]
        public bool $orphaned,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ReviewReplyResponse::class)))]
        public array $thread,
    ) {
    }
}
