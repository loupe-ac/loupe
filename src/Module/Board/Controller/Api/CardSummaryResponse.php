<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** The lean row of the card list. CardPayload::forCardList() builds the real body. */
#[OA\Schema(required: ['cardId', 'url', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt', 'state'])]
final readonly class CardSummaryResponse
{
    /** @param array{kind: string, code: string, since: string}|null $state */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The card page in the app.', format: 'uri')]
        public string $url,

        #[OA\Property(description: 'The short label that counts from 1 inside this project.')]
        public int $number,
        public string $title,

        #[OA\Property(description: 'A type key of the project.')]
        public string $type,

        #[OA\Property(description: 'The slug of the column.')]
        public string $status,

        #[OA\Property(enum: ['human', 'agent', 'reviewer', 'api-key', 'system'])]
        public string $reporter,

        #[OA\Property(format: 'uuid', nullable: true)]
        public ?string $parentCardId,

        #[OA\Property(format: 'date-time')]
        public string $updatedAt,

        #[OA\Property(description: 'What the card needs now on the board, or null for a card in a terminal column and a card with no mark.', required: ['kind', 'code', 'since'], properties: [
            new OA\Property(property: 'kind', type: 'string', enum: ['stuck', 'needs-you', 'working', 'waiting']),
            new OA\Property(property: 'code', description: 'Why.', type: 'string'),
            new OA\Property(property: 'since', type: 'string', format: 'date-time'),
        ], type: 'object', nullable: true)]
        public ?array $state,
    ) {
    }
}
