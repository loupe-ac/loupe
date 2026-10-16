<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

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
         * The branches that have a worktree on the bridge, the agent's pick first.
         *
         * @var list<RunCommandBranch>
         */
        public array $branches,
        /** The folder of the picked branch, or null when it has no worktree. */
        public ?string $folder,
        /** @var list<RunCommandParam> */
        public array $params,
        /**
         * The argv of the entry with the parameter values filled in. Built-in placeholders stay as they are.
         *
         * @var list<string>
         */
        public array $argv,
        public RunCommandOutcome $onSuccess,
        /** The translation key of the reason the action is off, or null when the owner can run it. */
        public ?string $disabledReasonKey,
        public ?RunCommandRun $run = null,
        /**
         * The argv of the entry with the `{name}` placeholders of its parameters left in, for the dialog to fill as the owner types.
         *
         * @var list<string>
         */
        public array $argvTemplate = [],
    ) {
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
