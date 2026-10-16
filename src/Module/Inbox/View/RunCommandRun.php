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
    ) {
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
