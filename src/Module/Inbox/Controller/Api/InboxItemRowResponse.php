<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use OpenApi\Attributes as OA;

/** One item of a list, as the spec shows it. InboxItemPayload::forListItem() builds the real row. */
#[OA\Schema(required: ['itemId', 'number', 'kind', 'origin', 'title', 'state', 'closedBy', 'closeCause', 'dismissible', 'blocking', 'createdAt', 'updatedAt', 'closedAt', 'cardNumbers'])]
final readonly class InboxItemRowResponse
{
    /** @param list<int> $cardNumbers */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $itemId,

        #[OA\Property(description: 'The short label that counts from 1 inside this project.')]
        public int $number,

        #[OA\Property(enum: ['question', 'todo', 'review', 'wait', 'notice', 'workflow'])]
        public string $kind,

        #[OA\Property(description: 'Who wrote the item: `agent`, `loupe`, or `api-key` for an item an API key created.', enum: ['agent', 'loupe', 'api-key'])]
        public string $origin,
        public string $title,

        #[OA\Property(enum: ['open', 'resolved', 'declined', 'cancelled'])]
        public string $state,

        #[OA\Property(description: 'Who closed the item.', nullable: true, enum: ['owner', 'owner-through-agent', 'owner-through-api-key', 'agent', 'loupe', 'rule'])]
        public ?string $closedBy,

        #[OA\Property(description: 'Why Loupe, a rule or an agent closed the item. Null for a response of the owner.', nullable: true)]
        public ?string $closeCause,

        #[OA\Property(description: 'Whether the owner may dismiss the item.')]
        public bool $dismissible,

        #[OA\Property(description: 'Whether an agent waits on the item.')]
        public bool $blocking,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(format: 'date-time')]
        public string $updatedAt,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $closedAt,

        #[OA\Property(description: 'The numbers of the linked cards, lowest first.', type: 'array', items: new OA\Items(type: 'integer'))]
        public array $cardNumbers,
    ) {
    }
}
