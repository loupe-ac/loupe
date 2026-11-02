<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** A feedback item in the row of a card list: its id, its status and its page, without its text and its marks. */
#[OA\Schema(required: ['id', 'url', 'pageUrl', 'status'])]
final readonly class FeedbackRefResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(description: 'The Feedback tab of the card in the app, scrolled to the note.', format: 'uri')]
        public string $url,

        #[OA\Property(description: 'The page of the reviewed site where a web note was left, or null for a native note.', nullable: true)]
        public ?string $pageUrl,
        public string $status,
    ) {
    }
}
