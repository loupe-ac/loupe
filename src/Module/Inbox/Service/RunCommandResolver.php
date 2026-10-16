<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\View\RunCommandBranch;
use App\Module\Inbox\View\RunCommandOutcome;
use App\Module\Inbox\View\RunCommandParam;
use App\Module\Inbox\View\RunCommandRun;
use App\Module\Inbox\View\RunCommandView;
use App\Module\Inbox\View\SuggestedAction;

/**
 * Checks a run-command suggestion against what the asking bridge reported in its last heartbeat:
 * the entry, each parameter value and the worktree of the branch. The bridge checks all of it again at the run.
 */
final readonly class RunCommandResolver
{
    public const string RUNNING = 'inbox.action.run-command.disabled.running';
    public const string NO_BRIDGE = 'inbox.action.run-command.disabled.no_bridge';
    public const string UNSUPPORTED = 'inbox.action.run-command.disabled.unsupported';
    public const string OFFLINE = 'inbox.action.run-command.disabled.offline';
    public const string ENTRY_MISSING = 'inbox.action.run-command.disabled.entry_missing';
    public const string PARAM_INVALID = 'inbox.action.run-command.disabled.param_invalid';
    public const string NO_WORKTREE = 'inbox.action.run-command.disabled.no_worktree';

    public function __construct(
        private BridgeLiveness $liveness,
    ) {
    }

    /**
     * @param array<string, string>|null $params the values the owner confirmed, else the ones of the suggestion
     */
    public function view(InboxItem $item, SuggestedAction $suggestion, ?Bridge $bridge, ?string $branch = null, ?array $params = null, ?RunCommandRun $run = null): RunCommandView
    {
        $command = $suggestion->command ?? throw new \LogicException('A run-command suggestion carries its command.');
        $branch ??= $command['branch'];
        $values = $params ?? $command['params'];
        $row = array_find($bridge->commandEntries ?? [], static fn (array $row): bool => $row['name'] === $command['entry']);

        $branches = $this->branches($item, $bridge, $command['branch']);
        $folder = array_find($branches, static fn (RunCommandBranch $candidate): bool => $candidate->branch === $branch)?->path;

        $paramViews = [];
        $argv = [];
        $valid = true;
        if (null !== $row) {
            $declared = [];
            foreach ($row['params'] as $param) {
                $declared[$param['name']] = true;
                $value = $values[$param['name']] ?? null;
                $fits = null !== $value && false !== SuggestedCommandCheck::matches($param['pattern'], $value);
                $valid = $valid && $fits;
                $paramViews[] = new RunCommandParam($param['name'], $value ?? '', $param['pattern'], $param['description'], $fits);
            }
            foreach ($values as $name => $value) {
                if (!isset($declared[(string) $name])) {
                    $valid = false;
                    $paramViews[] = new RunCommandParam((string) $name, $value, null, null, false);
                }
            }
            $replacements = [];
            foreach ($row['params'] as $param) {
                $replacements['{'.$param['name'].'}'] = $values[$param['name']] ?? '';
            }
            $argv = array_map(static fn (string $element): string => strtr($element, $replacements), $row['run']);
        } else {
            foreach ($values as $name => $value) {
                $paramViews[] = new RunCommandParam((string) $name, $value, null, null, true);
            }
        }

        $reason = match (true) {
            $run?->state->isLive() ?? false => self::RUNNING,
            null === $bridge => self::NO_BRIDGE,
            !$bridge->takesRunCommands() => self::UNSUPPORTED,
            $this->isQuiet($bridge, $item) => self::OFFLINE,
            null === $row => self::ENTRY_MISSING,
            !$valid => self::PARAM_INVALID,
            null === $folder || !WorkRequest::isBranchName($branch) => self::NO_WORKTREE,
            default => null,
        };

        return new RunCommandView(
            entry: $command['entry'],
            branch: $branch,
            bridgeId: $bridge?->id->toRfc4122(),
            bridgeName: $bridge?->label,
            entryMissing: null !== $bridge && null === $row,
            proposedEntry: $suggestion->proposedEntry,
            branches: $branches,
            folder: $folder,
            params: $paramViews,
            argv: $argv,
            onSuccess: InboxItemKind::Todo === $item->kind ? RunCommandOutcome::ClosesTodo : RunCommandOutcome::ResumesRun,
            disabledReasonKey: $reason,
            run: $run,
            argvTemplate: $row['run'] ?? [],
        );
    }

    /**
     * The branches of this project that have a worktree on the bridge, the agent's pick first, then by name.
     *
     * @return list<RunCommandBranch>
     */
    private function branches(InboxItem $item, ?Bridge $bridge, string $pick): array
    {
        $projectId = (string) $item->project->id;
        $branches = [];
        foreach ($bridge->worktrees ?? [] as $worktree) {
            if ($worktree['projectId'] === $projectId && WorkRequest::isBranchName($worktree['branch']) && !isset($branches[$worktree['branch']])) {
                $branches[$worktree['branch']] = new RunCommandBranch($worktree['branch'], $worktree['path']);
            }
        }
        uksort($branches, static fn (string $a, string $b): int => [$pick === $a ? 0 : 1, $a] <=> [$pick === $b ? 0 : 1, $b]);

        return array_values($branches);
    }

    private function isQuiet(Bridge $bridge, InboxItem $item): bool
    {
        $status = $this->liveness->forOwner($item->project->owner, [$bridge->id])[$bridge->id->toRfc4122()] ?? null;

        return null === $status || $status->quiet;
    }
}
