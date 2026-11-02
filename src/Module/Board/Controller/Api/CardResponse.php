<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use OpenApi\Attributes as OA;

/**
 * One whole card, as the spec shows it. CardPayload::forCard() builds the real body.
 * A nested object of a few fields is described in line.
 */
#[OA\Schema(required: ['cardId', 'number', 'title', 'body', 'type', 'status', 'reporter', 'position', 'completedAt', 'createdAt', 'updatedAt', 'pullRequests', 'documents', 'artifacts', 'siteReviewComments', 'relatedCards', 'parent', 'laneEnabled', 'requiresHumanReview', 'values', 'children', 'progress', 'pause', 'state'])]
final readonly class CardResponse
{
    /**
     * @param list<array<string, mixed>>        $pullRequests
     * @param list<array<string, mixed>>        $documents
     * @param list<array<string, mixed>>        $artifacts
     * @param list<array<string, mixed>>        $siteReviewComments
     * @param list<array<string, mixed>>        $relatedCards
     * @param array<string, mixed>|null         $parent
     * @param list<array<string, mixed>>        $values
     * @param list<array<string, mixed>>        $children
     * @param array{done: int, total: int}|null $progress
     * @param array<string, mixed>|null         $pause
     * @param array<string, mixed>|null         $state
     */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $cardId,

        #[OA\Property(description: 'The short label that counts from 1 inside this project.')]
        public int $number,
        public string $title,

        #[OA\Property(description: 'Markdown.')]
        public string $body,

        #[OA\Property(description: 'A type key of the project.')]
        public string $type,

        #[OA\Property(description: 'The slug of the column.')]
        public string $status,

        #[OA\Property(description: 'Who first raised the card.', enum: ['human', 'agent', 'reviewer', 'api-key', 'system'])]
        public string $reporter,

        #[OA\Property(description: 'The rank inside the column, counting from 0.')]
        public int $position,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $completedAt,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(format: 'date-time')]
        public string $updatedAt,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['pullRequestId', 'url', 'forge', 'repository', 'number', 'state', 'agentReview'],
            properties: [
                new OA\Property(property: 'pullRequestId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'forge', type: 'string'),
                new OA\Property(property: 'repository', type: 'string', nullable: true),
                new OA\Property(property: 'number', type: 'integer', nullable: true),
                new OA\Property(property: 'state', description: 'The last state Loupe read from the forge: state, draft, checks, failedChecks, mergeability, review, readyToMerge and refreshedAt. Null when Loupe holds no reading.', type: 'object', nullable: true, additionalProperties: true),
                new OA\Property(property: 'agentReview', description: 'The agent review of the pull request, or null.', type: 'object', nullable: true, additionalProperties: true),
            ],
            type: 'object',
        ))]
        public array $pullRequests,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['documentId', 'title', 'status'],
            properties: [
                new OA\Property(property: 'documentId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
            ],
            type: 'object',
        ))]
        public array $documents,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['artifactId', 'name', 'versionNumber', 'archived', 'path'],
            properties: [
                new OA\Property(property: 'artifactId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'versionNumber', type: 'integer'),
                new OA\Property(property: 'archived', type: 'boolean'),
                new OA\Property(property: 'path', description: 'The path of the artifact page in the app.', type: 'string'),
            ],
            type: 'object',
        ))]
        public array $artifacts,

        #[OA\Property(description: 'The feedback items of the card: id, url, surface, native, screenshot, anchors, body, hasDrawing, status, context and createdAt.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true))]
        public array $siteReviewComments,

        #[OA\Property(type: 'array', items: new OA\Items(
            required: ['cardId', 'number', 'title', 'status', 'kind'],
            properties: [
                new OA\Property(property: 'cardId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'number', type: 'integer'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'kind', description: 'How this card reads the other card.', type: 'string', enum: ['relates-to', 'blocks', 'blocked-by', 'has-follow-up', 'follow-up-of']),
            ],
            type: 'object',
        ))]
        public array $relatedCards,

        #[OA\Property(required: ['cardId', 'number', 'title', 'status'], properties: [
            new OA\Property(property: 'cardId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'number', type: 'integer'),
            new OA\Property(property: 'title', type: 'string'),
            new OA\Property(property: 'status', type: 'string'),
        ], type: 'object', nullable: true)]
        public ?array $parent,
        public bool $laneEnabled,

        #[OA\Property(description: 'True when a person must review the pull request.')]
        public bool $requiresHumanReview,

        #[OA\Property(description: 'One row for each key the workflow template declares.', type: 'array', items: new OA\Items(
            required: ['key', 'type', 'value', 'writer', 'updatedAt'],
            properties: [
                new OA\Property(property: 'key', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['bool']),
                new OA\Property(property: 'value', description: 'The value of the key, or null when it is unset.', type: 'boolean', nullable: true),
                new OA\Property(property: 'writer', description: 'Who last wrote the value: kind and name.', type: 'object', nullable: true, additionalProperties: true),
                new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
            ],
            type: 'object',
        ))]
        public array $values,

        #[OA\Property(description: 'Empty for a type without the children capability.', type: 'array', items: new OA\Items(
            required: ['cardId', 'number', 'title', 'status'],
            properties: [
                new OA\Property(property: 'cardId', type: 'string', format: 'uuid'),
                new OA\Property(property: 'number', type: 'integer'),
                new OA\Property(property: 'title', type: 'string'),
                new OA\Property(property: 'status', type: 'string'),
            ],
            type: 'object',
        ))]
        public array $children,

        #[OA\Property(description: 'Null for a type without the children capability.', required: ['done', 'total'], properties: [
            new OA\Property(property: 'done', type: 'integer'),
            new OA\Property(property: 'total', type: 'integer'),
        ], type: 'object', nullable: true)]
        public ?array $progress,

        #[OA\Property(description: 'The active workflow pause, or null.', required: ['pauseId', 'kind', 'reason', 'ruleId', 'since'], properties: [
            new OA\Property(property: 'pauseId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'kind', type: 'string', enum: ['rule', 'retries', 'work-limit', 'work-timeout', 'work-stopped']),
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'ruleId', type: 'string'),
            new OA\Property(property: 'since', type: 'string', format: 'date-time'),
        ], type: 'object', nullable: true)]
        public ?array $pause,

        #[OA\Property(description: 'What the card needs now on the board. Null for a card in a terminal column and a card with no mark.', required: ['kind', 'code', 'since', 'reason', 'others'], properties: [
            new OA\Property(property: 'kind', type: 'string', enum: ['stuck', 'needs-you', 'working', 'waiting']),
            new OA\Property(property: 'code', description: 'Why.', type: 'string'),
            new OA\Property(property: 'since', type: 'string', format: 'date-time'),
            new OA\Property(property: 'reason', description: 'One sentence that says why.', type: 'string'),
            new OA\Property(property: 'others', description: 'The other reasons that apply, each with kind, code, reason and since.', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
        ], type: 'object', nullable: true)]
        public ?array $state,
    ) {
    }
}
