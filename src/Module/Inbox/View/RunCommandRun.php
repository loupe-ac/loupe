<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Inbox\Entity\InboxAction;

/** The newest command run of an inbox item, as the status block shows it. */
final readonly class RunCommandRun
{
    /** The refusal codes of a bridge, with the translation key of each. */
    public const array REASON_KEYS = [
        'entry-missing' => 'inbox.run_command.refusal.entry_missing',
        'entry-changed' => 'inbox.run_command.refusal.entry_changed',
        'param-invalid' => 'inbox.run_command.refusal.param_invalid',
        'worktree-missing' => 'inbox.run_command.refusal.worktree_missing',
        'placeholder-missing' => 'inbox.run_command.refusal.placeholder_missing',
    ];

    public const int OUTPUT_LINES = 10;

    /** The lines of the stored log that a running command shows when the page opens. */
    public const int LIVE_LINES = 200;

    public function __construct(
        public InboxAction $action,
        public RunCommandRunState $state,
        /** The reason code of a refused request, or null. */
        public ?string $reason = null,
        public ?int $exitCode = null,
        /**
         * The last lines of the output, oldest first.
         *
         * @var list<string>
         */
        public array $outputLines = [],
        public ?\DateTimeImmutable $at = null,
        /** The id of the worker run, or null when the bridge has reported none. */
        public ?string $runId = null,
        /** The stored log lines, which are the line breaks. */
        public int $lineCount = 0,
        /** The bytes of the stored log, which is also where the live view reads on. */
        public int $logLength = 0,
        public ?int $durationSeconds = null,
        /** The exact end of the stored log, as the live view starts from it. */
        public string $tailText = '',
    ) {
    }

    /** The run has stored output to open. */
    public function hasLog(): bool
    {
        return null !== $this->runId && $this->logLength > 0;
    }

    /** The command runs and its worker run is known, so its output can stream in. */
    public function isStreaming(): bool
    {
        return RunCommandRunState::Running === $this->state && null !== $this->runId;
    }

    /** The duration as `14 s` or `3 min 12 s`, or null when the run has none. */
    public function durationLabel(): ?string
    {
        if (null === $this->durationSeconds) {
            return null;
        }

        return $this->durationSeconds < 60
            ? $this->durationSeconds.' s'
            : intdiv($this->durationSeconds, 60).' min '.($this->durationSeconds % 60).' s';
    }

    /** The translation key of the refusal reason, or null when the code is unknown or absent. */
    public function reasonKey(): ?string
    {
        return null === $this->reason ? null : (self::REASON_KEYS[$this->reason] ?? null);
    }

    /** @return list<string> */
    public static function tail(string $output): array
    {
        $lines = preg_split('/\R/', rtrim($output)) ?: [];
        $lines = array_slice($lines, -self::OUTPUT_LINES);

        return [''] === $lines ? [] : array_values($lines);
    }
}
