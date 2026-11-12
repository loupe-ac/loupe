<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use OpenApi\Attributes as OA;

/** One worker run, as the spec shows it. WorkerRunApiPayload builds the real body. */
#[OA\Schema(required: ['runId', 'runKey', 'kind', 'subjectType', 'subjectId', 'cardNumber', 'workRequestId', 'workKind', 'ruleId', 'state', 'sessionId', 'startedAt', 'endedAt', 'exitCode', 'bridgeId', 'harness', 'account', 'harnessSessionId', 'harnessVersion', 'cliVersion', 'skillVersion', 'pendingCommand', 'reason', 'usage', 'model', 'experiment', 'variant', 'metrics'])]
final readonly class WorkerRunResponse
{
    /**
     * @param array<string, mixed>|null  $pendingCommand
     * @param list<array<string, mixed>> $usage
     * @param array<string, mixed>|null  $metrics
     */
    public function __construct(
        #[OA\Property(description: 'The id of the run.', format: 'uuid')]
        public string $runId,

        #[OA\Property(description: 'The id the bridge gave the run, or null.', format: 'uuid', nullable: true)]
        public ?string $runKey,

        #[OA\Property(description: 'Worker for a run of an agent, interactive for a session that a person opened, and command for a command with no agent and so no session.', enum: ['worker', 'interactive', 'command'])]
        public string $kind,

        #[OA\Property(description: 'What the run is about, such as card.')]
        public string $subjectType,

        #[OA\Property(description: 'The id of the subject. For a card, the card id.', format: 'uuid')]
        public string $subjectId,

        #[OA\Property(description: 'The number of the card, or null when the subject is no card.', nullable: true)]
        public ?int $cardNumber,

        #[OA\Property(description: 'The work request that the run ran, or null.', format: 'uuid', nullable: true)]
        public ?string $workRequestId,

        #[OA\Property(description: 'The kind of that work request, such as implement or fix, or the name of an interactive session.', nullable: true)]
        public ?string $workKind,

        #[OA\Property(description: 'The id of the workflow rule that opened the request, or null.', nullable: true)]
        public ?string $ruleId,

        #[OA\Property(description: 'The state of the run, such as running, failed, gave-up, timed-out or lost.')]
        public string $state,

        #[OA\Property(description: 'The Loupe session of the worker, or null.', format: 'uuid', nullable: true)]
        public ?string $sessionId,

        #[OA\Property(description: 'When the run started, or null.', format: 'date-time', nullable: true)]
        public ?string $startedAt,

        #[OA\Property(description: 'When the run ended, or null while it is open.', format: 'date-time', nullable: true)]
        public ?string $endedAt,

        #[OA\Property(description: 'The exit code, or null.', nullable: true)]
        public ?int $exitCode,

        #[OA\Property(description: 'The bridge that ran the run, or null.', format: 'uuid', nullable: true)]
        public ?string $bridgeId,

        #[OA\Property(description: 'The harness that ran the run, such as claude-code or codex, or null until the bridge reports it.', nullable: true)]
        public ?string $harness,

        #[OA\Property(description: 'The named harness account, or null.', nullable: true)]
        public ?string $account,

        #[OA\Property(description: 'The id the harness gave the session, such as a Codex thread id, or null.', nullable: true)]
        public ?string $harnessSessionId,

        #[OA\Property(description: 'The version of the harness, or null.', nullable: true)]
        public ?string $harnessVersion,

        #[OA\Property(description: 'The version of the CLI that ran the run, or null.', nullable: true)]
        public ?string $cliVersion,

        #[OA\Property(description: 'The version of the skill package, or null.', nullable: true)]
        public ?string $skillVersion,

        #[OA\Property(description: 'The command that waits for the bridge, or null.', required: ['commandId', 'kind', 'state'], properties: [
            new OA\Property(property: 'commandId', type: 'string', format: 'uuid'),
            new OA\Property(property: 'kind', type: 'string', enum: ['resume-run', 'stop-run', 'rerun-command', 'collect-session-usage', 'try-now']),
            new OA\Property(property: 'state', type: 'string', enum: ['pending']),
        ], type: 'object', nullable: true)]
        public ?array $pendingCommand,

        #[OA\Property(description: 'The failure reason, or else the last line of the output, cut to 300 characters, or null.', nullable: true)]
        public ?string $reason,

        #[OA\Property(description: 'The tokens of each model the run used. Empty when the bridge sent no usage. `costUsd` is a decimal string in US dollars, or null when the model has no price.', type: 'array', items: new OA\Items(required: ['model', 'source', 'inputTokens', 'outputTokens', 'cacheReadTokens', 'cacheWriteTokens', 'costUsd'], properties: [
            new OA\Property(property: 'model', type: 'string'),
            new OA\Property(property: 'source', type: 'string', enum: ['reported', 'estimated']),
            new OA\Property(property: 'inputTokens', type: 'integer'),
            new OA\Property(property: 'outputTokens', type: 'integer'),
            new OA\Property(property: 'cacheReadTokens', type: 'integer'),
            new OA\Property(property: 'cacheWriteTokens', type: 'integer'),
            new OA\Property(property: 'costUsd', type: 'string', nullable: true),
        ], type: 'object'))]
        public array $usage,

        #[OA\Property(description: 'The model the bridge reported, or else the model with the highest cost, or null.', nullable: true)]
        public ?string $model,

        #[OA\Property(description: 'The experiment of the rule that ran the worker, or null.', nullable: true)]
        public ?string $experiment,

        #[OA\Property(description: 'The variant of that experiment, or null.', nullable: true)]
        public ?string $variant,

        #[OA\Property(description: 'The sums and the timing of the run, or null when the run has no metrics row. A figure is null when it is unknown. `costUsd` is a number in US dollars. The `concurrentRuns` and host figures are null while the run has no end, or when the bridge sent no sample.', type: 'object', nullable: true, additionalProperties: true)]
        public ?array $metrics,
    ) {
    }
}
