<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One tool call of a run, as the spec shows it. */
#[OA\Schema(required: ['seq', 'tool', 'kind', 'startedAt', 'durationMs', 'isError', 'inSubagent', 'backgroundId', 'waitsOn', 'signatures', 'fullText'])]
final readonly class WorkerRunToolCallResponse
{
    /** @param list<string> $signatures */
    public function __construct(
        #[OA\Property(description: 'The place of the call in the run, from 1.', minimum: 1)]
        public int $seq,

        #[OA\Property(description: 'The tool, such as Bash, Read or Agent.')]
        public string $tool,

        #[OA\Property(description: 'Shell for a shell command, subagent for a call that starts a sub-agent, tool for any other call, or null from an older bridge.', nullable: true, enum: ['shell', 'subagent', 'tool'])]
        public ?string $kind,

        #[OA\Property(description: 'When the call started, with milliseconds.', format: 'date-time')]
        public string $startedAt,

        #[OA\Property(description: 'How long the call took. Null when the stream holds no result of the call.', nullable: true)]
        public ?int $durationMs,

        #[OA\Property(description: 'Whether the call failed. Null when the stream holds no result of the call.', nullable: true)]
        public ?bool $isError,

        #[OA\Property(description: 'True when a subagent made the call.')]
        public bool $inSubagent,

        #[OA\Property(description: 'The id of the background task that the call started, or null.', nullable: true)]
        public ?string $backgroundId,

        #[OA\Property(description: 'The background id of an earlier call that this call reads, or null.', nullable: true)]
        public ?string $waitsOn,

        #[OA\Property(description: 'The program and its subcommand for each shell command, such as git status, or the tool name for any other tool.', type: 'array', items: new OA\Items(type: 'string'))]
        public array $signatures,

        #[OA\Property(description: 'The full text of the call. Null unless the project collects the full text of the calls.', nullable: true)]
        public ?string $fullText,
    ) {
    }
}
