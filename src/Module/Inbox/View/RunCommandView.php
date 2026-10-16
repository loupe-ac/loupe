<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\ValueObject\WorkRequestContext;

/**
 * Everything the inbox shows for a "Run a command" action: the main button, the confirm dialog and the
 * run state block. The values are what the agent suggested, until the owner confirms others.
 */
final readonly class RunCommandView
{
    public function __construct(
        /** The command entry of the rule file, which is also the kind of the work request. */
        public string $entry,
        /** The branch of the main button and the dialog pick. */
        public string $branch,
        /** The RFC 4122 id of the asking bridge, or null when none follows the project. */
        public ?string $bridgeId,
        public ?string $bridgeName,
        /** True when a bridge exists and its last heartbeat lists no entry of this name. */
        public bool $entryMissing,
        /** The YAML text of the entry the agent proposes, shown when the entry is missing. */
        public ?string $proposedEntry,
        /**
         * The branches that have a worktree on the bridge, the agent's pick first, with no folder when it has none.
         *
         * @var list<RunCommandBranch>
         */
        public array $branches,
        /** The folder of the picked branch, or null when it has no worktree. */
        public ?string $folder,
        /** @var list<RunCommandParam> */
        public array $params,
        /**
         * The argv of the entry with the built-in placeholders and the parameter values filled in, as the bridge runs it.
         *
         * @var list<string>
         */
        public array $argv,
        public RunCommandOutcome $onSuccess,
        /** The translation key of the reason the action is off, or null when the owner can run it. */
        public ?string $disabledReasonKey,
        public ?RunCommandRun $run = null,
        /**
         * The argv with the built-in placeholders filled in and those of the parameters left in, for the dialog to fill as the owner types.
         *
         * @var list<string>
         */
        public array $argvTemplate = [],
        /** The bridge sent no recent heartbeat, so a run waits in the queue until it comes back. */
        public bool $bridgeQuiet = false,
        /**
         * The run list of the entry with its placeholders, as the last heartbeat reported it.
         *
         * @var list<string>
         */
        public array $runList = [],
        /** The pull request values the built-in placeholders were filled from, which the work request must carry. */
        public ?WorkRequestContext $context = null,
    ) {
    }

    /** Names the run list and the built-in values the dialog shows, so a submit can tell that either changed after the owner read it. */
    public function runDigest(): string
    {
        return hash('sha256', json_encode([$this->runList, $this->argvTemplate], \JSON_THROW_ON_ERROR));
    }

    /** The owner can open the confirm dialog: a wrong branch or value is fixed there, so only those two reasons keep it open. */
    public function canOpenDialog(): bool
    {
        return null === $this->disabledReasonKey || \in_array($this->disabledReasonKey, ['inbox.action.run-command.disabled.param_invalid', 'inbox.action.run-command.disabled.no_worktree'], true);
    }

    /** @return array<string, string> the value of each parameter by name */
    public function values(): array
    {
        $values = [];
        foreach ($this->params as $param) {
            $values[$param->name] = $param->value;
        }

        return $values;
    }
}
