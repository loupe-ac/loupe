<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use OpenApi\Attributes as OA;

/**
 * One whole inbox item, as the spec shows it. InboxItemPayload::forItem() builds the real body,
 * the same one inbox_get returns.
 */
#[OA\Schema(required: ['itemId', 'number', 'kind', 'origin', 'title', 'state', 'closedBy', 'closeCause', 'dismissible', 'blocking', 'createdAt', 'updatedAt', 'closedAt', 'cardNumbers', 'body', 'options', 'multiple', 'freeText', 'selectedOptions', 'note', 'review', 'cards', 'documents', 'asks', 'cardId', 'waits', 'suggestedActions', 'actions', 'apiKey'])]
final readonly class InboxItemResponse
{
    /**
     * @param list<int>                            $cardNumbers
     * @param list<string>                         $options
     * @param list<int>                            $selectedOptions
     * @param array<string, mixed>|null            $review
     * @param list<array<string, mixed>>           $cards
     * @param list<array<string, mixed>>           $documents
     * @param list<array<string, mixed>>           $asks
     * @param list<array<string, mixed>>           $waits
     * @param list<array<string, mixed>>           $suggestedActions
     * @param list<array<string, mixed>>           $actions
     * @param array{id: string, name: string}|null $apiKey
     */
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

        #[OA\Property(description: 'Who closed the item.', nullable: true, enum: ['owner', 'owner-through-agent', 'agent', 'loupe', 'rule'])]
        public ?string $closedBy,

        #[OA\Property(description: 'Why Loupe, a rule or an agent closed the item. Null for a response of the owner.', nullable: true)]
        public ?string $closeCause,

        #[OA\Property(description: 'Whether the owner may dismiss the item.')]
        public bool $dismissible,

        #[OA\Property(description: 'Whether an agent waits on the item. Always false for an item from the API.')]
        public bool $blocking,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(format: 'date-time')]
        public string $updatedAt,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $closedAt,

        #[OA\Property(description: 'The numbers of the linked cards, lowest first.', type: 'array', items: new OA\Items(type: 'integer'))]
        public array $cardNumbers,

        #[OA\Property(description: 'Markdown.', nullable: true)]
        public ?string $body,

        #[OA\Property(type: 'array', items: new OA\Items(type: 'string'))]
        public array $options,
        public bool $multiple,
        public bool $freeText,

        #[OA\Property(description: 'The indexes of the options the owner picked, counting from 0.', type: 'array', items: new OA\Items(type: 'integer'))]
        public array $selectedOptions,

        #[OA\Property(description: 'The words of whoever closed the item: a written answer, a dismiss note or a withdraw reason.', nullable: true)]
        public ?string $note,

        #[OA\Property(description: 'The target and the verdict of a review item, otherwise null.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $review,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['cardId', 'number', 'title'],
            properties: [
                new OA\Property(property: 'cardId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'number', type: 'integer'),
                new OA\Property(property: 'title', type: 'string'),
            ],
            type: 'object',
        ))]
        public array $cards,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['documentId', 'title'],
            properties: [
                new OA\Property(property: 'documentId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'title', type: 'string'),
            ],
            type: 'object',
        ))]
        public array $documents,

        #[OA\Property(description: 'Every ask that holds the item. An ask with no session is held by Loupe or made by the API.', type: 'array', items: new OA\Items(
            required: ['askId', 'origin', 'sessionId', 'closedAt'],
            properties: [
                new OA\Property(property: 'askId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'origin', type: 'string'),
                new OA\Property(property: 'sessionId', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'closedAt', type: 'string', format: 'date-time', nullable: true),
            ],
            type: 'object',
        ))]
        public array $asks,

        #[OA\Property(description: 'The card of an automatic item, otherwise null.', format: 'uuid', nullable: true)]
        public ?string $cardId,

        #[OA\Property(description: 'Each reason the card of an automatic item waited, oldest first. Empty for other items.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true))]
        public array $waits,

        #[OA\Property(description: 'The built-in actions the asking agent suggests.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true))]
        public array $suggestedActions,

        #[OA\Property(description: 'Each action the owner ran on the item, oldest first.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true))]
        public array $actions,

        #[OA\Property(
            description: 'The key that created the item when `origin` is `api-key`, otherwise null. The name is the one the key had then, and stays when the key is removed.',
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
            ],
            type: 'object',
            nullable: true,
        )]
        public ?array $apiKey,
    ) {
    }
}
