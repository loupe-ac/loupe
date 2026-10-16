<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Workflow\WorkRequestContexts;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\View\RunCommandBranch;
use App\Module\Inbox\View\RunCommandOutcome;
use App\Module\Inbox\View\RunCommandParam;
use App\Module\Inbox\View\RunCommandRun;
use App\Module\Inbox\View\RunCommandView;
use App\Module\Inbox\View\SuggestedAction;
use Symfony\Component\Uid\Uuid;

/**
 * Checks a run-command suggestion against what the asking bridge reported in its last heartbeat:
 * the entry, each parameter value and the worktree of the branch. The bridge checks all of it again at the run.
 */
final readonly class RunCommandResolver
{
    public const string RUNNING = 'inbox.action.run-command.disabled.running';
    public const string NO_BRIDGE = 'inbox.action.run-command.disabled.no_bridge';
    public const string UNSUPPORTED = 'inbox.action.run-command.disabled.unsupported';
    public const string ENTRY_MISSING = 'inbox.action.run-command.disabled.entry_missing';
    public const string PARAM_INVALID = 'inbox.action.run-command.disabled.param_invalid';
    public const string NO_WORKTREE = 'inbox.action.run-command.disabled.no_worktree';
    public const string PLACEHOLDER_MISSING = 'inbox.action.run-command.disabled.placeholder_missing';
    public const string PLACEHOLDER_UNKNOWN = 'inbox.action.run-command.disabled.placeholder_unknown';

    private const array CONTEXT_PLACEHOLDERS = ['pullRequestNumber', 'pullRequestUrl', 'headSha', 'reason', 'documentId'];

    /** The bridge fills these only when it takes the request, so the dialog cannot show their values. */
    private const array UNKNOWN_PLACEHOLDERS = ['project', 'workRequestId'];

    public function __construct(
        private BridgeLiveness $liveness,
        private CardRepository $cards,
        private WorkRequestContexts $contexts,
    ) {
    }

    /**
     * @param array<string, string>|null $params the values the owner confirmed, else the ones of the suggestion
     * @param Uuid|null                  $cardId the card the run belongs to, which fills the card placeholders
     */
    public function view(InboxItem $item, SuggestedAction $suggestion, ?Bridge $bridge, ?Uuid $cardId, ?string $branch = null, ?array $params = null, ?RunCommandRun $run = null): RunCommandView
    {
        $command = $suggestion->command ?? throw new \LogicException('A run-command suggestion carries its command.');
        $branch ??= $command['branch'];
        $values = $params ?? $command['params'];
        $row = array_find($bridge->commandEntries ?? [], static fn (array $row): bool => $row['name'] === $command['entry']);

        $branches = $this->branches($item, $bridge, $command['branch']);
        $folder = array_find($branches, static fn (RunCommandBranch $candidate): bool => $candidate->branch === $branch)?->path;

        $paramViews = [];
        $argv = [];
        $argvTemplate = [];
        $placeholderReason = null;
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
            [$builtIns, $placeholderReason] = $this->builtIns($item, $command['entry'], $cardId, $row['run'], $declared);
            $argvTemplate = array_map(static fn (string $element): string => strtr($element, $builtIns), $row['run']);
            $replacements = $builtIns;
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
            null === $row => self::ENTRY_MISSING,
            null !== $placeholderReason => $placeholderReason,
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
            argvTemplate: $argvTemplate,
            bridgeQuiet: null !== $bridge && $this->isQuiet($bridge, $item),
            runList: $row['run'] ?? [],
        );
    }

    /**
     * The values the bridge gives the built-in placeholders of the argv, as `{name}` => value, and the reason the dialog cannot show
     * the argv the bridge runs: a placeholder the bridge fills only at the run, or a card placeholder of a run with no card.
     *
     * @param list<string>        $run
     * @param array<string, true> $params the names of the parameters of the entry
     *
     * @return array{array<string, string>, ?string}
     */
    private function builtIns(InboxItem $item, string $entry, ?Uuid $cardId, array $run, array $params): array
    {
        $used = [];
        foreach ($run as $element) {
            preg_match_all('/\{([A-Za-z]+)\}/', $element, $matches);
            foreach ($matches[1] as $name) {
                if (!isset($params[$name])) {
                    $used[$name] = true;
                }
            }
        }
        if ([] !== array_intersect(array_keys($used), self::UNKNOWN_PLACEHOLDERS)) {
            return [[], self::PLACEHOLDER_UNKNOWN];
        }
        $card = null;
        if (null !== $cardId && (isset($used['cardNumber']) || [] !== array_intersect(array_keys($used), self::CONTEXT_PLACEHOLDERS))) {
            $card = $this->cards->find($cardId);
        }
        if ((isset($used['cardId']) && null === $cardId) || (isset($used['cardNumber']) && null === $card)) {
            return [[], self::PLACEHOLDER_MISSING];
        }
        $context = null === $card || [] === array_intersect(array_keys($used), self::CONTEXT_PLACEHOLDERS) ? null : $this->contexts->forCard($card, null);
        $itemId = (string) ($item->id ?? throw new \LogicException('A stored item has an id.'));
        $values = [
            'cardId' => null === $cardId ? '' : (string) $cardId,
            'cardNumber' => null === $card ? '' : (string) $card->number,
            'subjectType' => null === $cardId ? InboxItem::SUBJECT_TYPE : WorkSubject::CARD,
            'subjectId' => null === $cardId ? $itemId : (string) $cardId,
            'projectId' => (string) $item->project->id,
            'kind' => $entry,
            'ruleId' => RunInboxActionHandler::RUN_COMMAND_RULE_ID,
            'pullRequestNumber' => null === $context?->pullRequestNumber ? '' : (string) $context->pullRequestNumber,
            'pullRequestUrl' => $context->pullRequestUrl ?? '',
            'headSha' => $context->headSha ?? '',
            'reason' => $context->reason ?? '',
            'documentId' => $context->documentId ?? '',
        ];
        $builtIns = [];
        foreach ($values as $name => $value) {
            $builtIns['{'.$name.'}'] = $value;
        }

        return [$builtIns, null];
    }

    /**
     * The branches of this project that have a worktree on the bridge, the agent's pick first, then by name.
     * The agent's pick shows with no folder when the bridge has no worktree for it.
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
        if (!isset($branches[$pick]) && WorkRequest::isBranchName($pick)) {
            $branches[$pick] = new RunCommandBranch($pick, null);
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
