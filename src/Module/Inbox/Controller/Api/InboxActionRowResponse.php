<?php

declare(strict_types=1);

namespace App\Module\Inbox\Controller\Api;

use OpenApi\Attributes as OA;

/** One action a worker can suggest, as the spec shows it. */
#[OA\Schema(required: ['action', 'effect', 'fitsCard'])]
final readonly class InboxActionRowResponse
{
    public function __construct(
        #[OA\Property(enum: ['resume-run', 'retry-card', 'review', 'open', 'send-agent', 'start-session', 'run-command', 'dismiss'])]
        public string $action,

        #[OA\Property(description: 'One line on what the action does.')]
        public string $effect,

        #[OA\Property(description: 'Whether the action fits the card now. Null when the request names no card.', nullable: true)]
        public ?bool $fitsCard,
    ) {
    }
}
