<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use OpenApi\Attributes as OA;

/** What a run of an action answers, as the spec shows it. The controller builds the real body. */
#[OA\Schema(required: ['actionId', 'item'])]
final readonly class InboxActionResultResponse
{
    /** @param array<string, mixed> $item */
    public function __construct(
        #[OA\Property(description: 'The id of the action that Loupe recorded, or null for a response, which records the closed item alone.', format: 'uuid', nullable: true)]
        public ?string $actionId,

        #[OA\Property(ref: new \Nelmio\ApiDocBundle\Attribute\Model(type: InboxItemResponse::class), description: 'The item after the action.')]
        public array $item,
    ) {
    }
}
