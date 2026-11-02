<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/** One history row, as the spec shows it. CardEventPayload builds the real body. */
#[OA\Schema(description: 'A missing or malformed key of the stored detail reads null.', required: ['kind', 'occurredAt', 'actor', 'from', 'to', 'cause', 'run', 'reason', 'pullRequest', 'pause', 'override', 'document', 'humanReview', 'valueChange'])]
final readonly class CardEventResponse
{
    /**
     * @param array{kind: string, name: ?string, apiKey: ?array{id: string, name: string}} $actor
     * @param array<string, mixed>|null                                                    $from
     * @param array<string, mixed>|null                                                    $to
     * @param array<string, mixed>|null                                                    $cause
     * @param array<string, mixed>|null                                                    $run
     * @param array<string, mixed>|null                                                    $pause
     * @param array<string, mixed>|null                                                    $override
     * @param array<string, mixed>|null                                                    $document
     * @param array<string, mixed>|null                                                    $humanReview
     * @param array<string, mixed>|null                                                    $valueChange
     */
    public function __construct(
        #[OA\Property(enum: ['created', 'moved', 'fix-requested', 'stopped', 'ready-to-merge', 'synced', 'run-finished', 'paused', 'pause-released', 'verdict', 'review-overridden', 'override-ended', 'resume-skipped', 'document-verdict', 'verdict-failed', 'auto-approved', 'approval-dismissed', 'human-review-changed', 'value-changed'])]
        public string $kind,

        #[OA\Property(format: 'date-time')]
        public string $occurredAt,

        #[OA\Property(description: 'Who made the change.', required: ['kind', 'name', 'apiKey'], properties: [
            new OA\Property(property: 'kind', description: 'The class of writer.', type: 'string', enum: ['human', 'agent', 'reviewer', 'system', 'api-key']),
            new OA\Property(property: 'name', description: 'The name of the person behind it, or null.', type: 'string', nullable: true),
            new OA\Property(
                property: 'apiKey',
                description: 'The key that acted when `kind` is `api-key`, otherwise null.',
                required: ['id', 'name'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'name', type: 'string'),
                ],
                type: 'object',
                nullable: true,
            ),
        ], type: 'object')]
        public array $actor,

        #[OA\Property(description: 'The column a move left, with id, slug and label.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $from,

        #[OA\Property(description: 'The column a move or a creation set, with id, slug and label.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $to,

        #[OA\Property(description: 'Why the app moved the card on its own, or null.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $cause,

        #[OA\Property(description: 'The whole record of a finished run, on a `run-finished` event.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $run,

        #[OA\Property(nullable: true)]
        public ?string $reason,

        #[OA\Property(nullable: true)]
        public ?int $pullRequest,

        #[OA\Property(description: 'On a `paused` or `pause-released` event: kind, ruleId and releaseReason.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $pause,

        #[OA\Property(description: 'On a `review-overridden` or `override-ended` event: approver and headSha.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $override,

        #[OA\Property(description: 'On a `document-verdict` or `verdict-failed` event: documentId, title, rule, verdict, withdrawn and refusal.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $document,

        #[OA\Property(description: 'On a `human-review-changed` event: value, cause and ruleId.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $humanReview,

        #[OA\Property(description: 'On a `value-changed` event: key, value and previous.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $valueChange,
    ) {
    }
}
