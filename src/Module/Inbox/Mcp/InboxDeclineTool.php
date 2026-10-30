<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\DeclineInboxItemCommand;
use App\Module\Inbox\Command\DeclineInboxItemHandler;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Declines an inbox question or to-do for the owner.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Decline an open inbox question or to-do for the owner, only after the owner told you in chat to decline it. On an automatic item, of kind wait or review, this dismisses the wait. Pass note, a reason the item keeps. The item shows that it was declined through an agent. A bridge worker session is refused, because a worker has no owner beside it. An agent\'s review ask is refused, because a review verdict stays on the inbox page. To record a verdict the owner gave in chat, call document_verdict_submit. A workflow question cannot be declined: answer it with inbox_answer, which is final and runs its action at once. The response is the item\'s summary.')]
final readonly class InboxDeclineTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_decline';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private WorkerSessionGuard $workers,
        private DeclineInboxItemHandler $decline,
        private InboxItemPayload $payload,
        private InboxToolErrorMessages $errorMessages,
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
     * @param string  $itemId the id of the question, to-do or automatic item
     * @param ?string $note   why the owner declined, which the item keeps
     *
     * @return InboxItemListSummary
     */
    public function __invoke(
        string $itemId,
        #[Schema(maxLength: RunInboxActionRequest::MAX_RESPONSE_LENGTH)]
        ?string $note = null,
    ): array {
        $this->gate->requireEnabled();

        try {
            $item = $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE);
            $this->workers->requireNotWorker($item->project);
            if (InboxItemKind::Review === $item->kind && !$item->isAutomatic()) {
                throw $this->errorMessages->forAgent(new DomainErrors(['itemId' => InboxToolErrorMessages::REVIEW_ON_PAGE]));
            }

            return $this->payload->forListItem(($this->decline)(new DeclineInboxItemCommand(
                item: $item,
                note: $note ?? '',
                throughAgent: true,
            )));
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The item could not be declined. The error has been logged.', previous: $e);
        }
    }
}
