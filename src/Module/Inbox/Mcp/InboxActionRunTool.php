<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Account\Entity\User;
use App\Module\Inbox\Command\RunInboxActionCommand;
use App\Module\Inbox\Command\RunInboxActionHandler;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\ItemActions;
use App\Module\Inbox\View\RunCommandView;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Runs a built-in action of an inbox item for the owner.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 *
 * @phpstan-type InboxActionPreview array{preview: true, message: string, itemId: string, entry: string, bridge: ?string, branch: string, runList: list<string>, argv: list<string>, params: array<string, string>, runDigest: string, disabledReason: ?string}
 * @phpstan-type InboxActionRun array{preview: false, actionId: ?string, item: InboxItemListSummary}
 */
#[McpTool(name: self::NAME, description: 'Run a built-in action of an open inbox item for the owner, only after the owner told you in chat to run it. Pass action: resume-run, retry-card, send-agent, start-session, run-command, add-entry or dismiss. The action must fit the item now; inbox_get lists the actions that fit. The open and review actions are links on the inbox page, so this tool refuses them, and a review verdict stays on the inbox page. A bridge worker session is refused, because a worker has no owner beside it. Pass note, a short text the action keeps; it stays in Loupe. A dismiss closes the item and shows it was closed through an agent. For run-command, call first without runDigest. Nothing runs. The response is a preview with the entry, the branch, the params, the run list and a runDigest. Show the preview to the owner, and let the owner confirm it in chat. Then call again with the same branch and params and with that runDigest. The command runs only when the digest matches the command the bridge reports now; otherwise call again without runDigest and show the new preview. branch and params apply to run-command only. The response of a run is the id of the recorded action, if the action records one, and the item\'s summary.')]
final readonly class InboxActionRunTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_action_run';

    /** @var list<InboxActionKind> */
    private const array RUNNABLE = [
        InboxActionKind::ResumeRun,
        InboxActionKind::RetryCard,
        InboxActionKind::SendAgent,
        InboxActionKind::StartSession,
        InboxActionKind::RunCommand,
        InboxActionKind::AddEntry,
        InboxActionKind::Dismiss,
    ];

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private WorkerSessionGuard $workers,
        private RunInboxActionHandler $run,
        private ItemActions $itemActions,
        private InboxItemPayload $payload,
        private InboxToolErrorMessages $errorMessages,
        private Security $security,
    ) {
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return InboxInstallFlags::FLAG_INBOX_ENABLED;
    }

    /**
     * @param string                     $itemId    the id of the item
     * @param string                     $action    the action to run
     * @param string                     $note      a short text the action keeps
     * @param ?string                    $branch    the branch to run a run-command on; the suggestion's branch when you leave it out
     * @param array<string, string>|null $params    the value of each param of a run-command; the suggestion's values when you leave it out
     * @param ?string                    $runDigest the runDigest of the preview the owner confirmed, for run-command
     *
     * @return InboxActionPreview|InboxActionRun
     */
    public function __invoke(
        string $itemId,
        #[Schema(enum: ['resume-run', 'retry-card', 'send-agent', 'start-session', 'run-command', 'add-entry', 'dismiss'])]
        string $action,
        #[Schema(maxLength: InboxAction::MAX_NOTE_LENGTH)]
        string $note = '',
        ?string $branch = null,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        ?array $params = null,
        ?string $runDigest = null,
    ): array {
        $this->gate->requireEnabled();

        try {
            $item = $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE);
            $this->workers->requireNotWorker($item->project);
            $kind = $this->requireKind($action);
            $values = $this->requireParams($params);

            if (InboxActionKind::RunCommand === $kind && null === $runDigest) {
                return $this->preview($item, $branch, $values);
            }

            $handlerDigest = InboxActionKind::RunCommand === $kind ? $this->handlerDigest($item, $branch, $values, (string) $runDigest) : null;

            $user = $this->security->getUser();
            $recorded = ($this->run)(new RunInboxActionCommand(
                item: $item,
                kind: $kind,
                note: $note,
                user: $user instanceof User ? $user : throw new \LogicException('An MCP call always authenticates a user.'),
                branch: $branch,
                params: $values,
                runDigest: $handlerDigest,
                sessionPrompt: InboxActionKind::StartSession === $kind ? ItemActions::suggestedSessionPrompt($item) : null,
                throughAgent: true,
            ));

            return ['preview' => false, 'actionId' => null === $recorded?->id ? null : (string) $recorded->id, 'item' => $this->payload->forListItem($item)];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The action could not be run. The error has been logged.', previous: $e);
        }
    }

    /**
     * Binds the digest of the preview to the item, the entry, the branch and the values it showed, because the digest of the view names only the bridge and the run list.
     *
     * @param array<string, string>|null $params
     */
    private function handlerDigest(InboxItem $item, ?string $branch, ?array $params, string $given): string
    {
        $view = InboxItemState::Open === $item->state ? $this->itemActions->runCommandFor($item, $branch, $params) : null;
        if (null === $view) {
            throw $this->errorMessages->forAgent(new DomainErrors(['action' => RunInboxActionHandler::NOT_AVAILABLE]));
        }
        if (!hash_equals(self::confirmation($item, $view), $given)) {
            throw $this->errorMessages->forAgent(new DomainErrors(['action' => RunInboxActionHandler::COMMAND_CHANGED]));
        }

        return $view->runDigest();
    }

    private static function confirmation(InboxItem $item, RunCommandView $view): string
    {
        return hash('sha256', json_encode([(string) $item->id, $view->entry, $view->runDigest(), $view->branch, $view->values()], \JSON_THROW_ON_ERROR));
    }

    private function requireKind(string $action): InboxActionKind
    {
        $kind = InboxActionKind::tryFrom($action);
        if (InboxActionKind::Open === $kind || InboxActionKind::Review === $kind) {
            throw new ToolCallException(\sprintf('action: "%s" is a link to a page, so the owner follows it in Loupe. A review verdict stays on the inbox page.', $action));
        }

        return null !== $kind && \in_array($kind, self::RUNNABLE, true)
            ? $kind
            : throw new ToolCallException(\sprintf('action: unknown action "%s". Use one of: %s.', $action, implode(', ', array_map(static fn (InboxActionKind $case): string => $case->value, self::RUNNABLE))));
    }

    /**
     * @param array<mixed>|null $params
     *
     * @return array<string, string>|null
     */
    private function requireParams(?array $params): ?array
    {
        if (null === $params) {
            return null;
        }
        if ([] !== $params && array_is_list($params)) {
            throw new ToolCallException('params: pass an object that maps each param name to a string.');
        }
        $strings = [];
        foreach ($params as $name => $value) {
            if (!\is_string($value)) {
                throw new ToolCallException(\sprintf('params.%s: pass a string.', $name));
            }
            $strings[(string) $name] = $value;
        }

        return $strings;
    }

    /**
     * @param array<string, string>|null $params
     *
     * @return InboxActionPreview
     */
    private function preview(InboxItem $item, ?string $branch, ?array $params): array
    {
        $view = InboxItemState::Open === $item->state ? $this->itemActions->runCommandFor($item, $branch, $params) : null;
        if (null === $view) {
            throw $this->errorMessages->forAgent(new DomainErrors(['action' => RunInboxActionHandler::NOT_AVAILABLE]));
        }

        return [
            'preview' => true,
            'message' => 'Nothing ran. Show this command to the owner. When the owner confirms it in chat, call inbox_action_run again with the same branch and params and with this runDigest.',
            'itemId' => (string) $item->id,
            'entry' => $view->entry,
            'bridge' => $view->bridgeName,
            'branch' => $view->branch,
            'runList' => $view->runList,
            'argv' => $view->argv,
            'params' => $view->values(),
            'runDigest' => self::confirmation($item, $view),
            'disabledReason' => null === $view->disabledReasonKey ? null : $this->errorMessages->sentence($view->disabledReasonKey),
        ];
    }
}
