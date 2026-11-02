<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** A feedback item in the row of a card list: its id, its status and its page, without its text and its marks. */
#[OA\Schema(required: ['id', 'status', 'url'])]
final readonly class FeedbackRefResponse
{
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        public string $status,

        #[OA\Property(description: 'The page of a web note, or null for a native note.', nullable: true)]
        public ?string $url,
    ) {
    }
}
