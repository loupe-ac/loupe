<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\MarkInboxItemDoneCommand;
use App\Module\Inbox\Command\MarkInboxItemDoneHandler;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Marks an inbox to-do done for the owner.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Mark an open inbox to-do done for the owner, only after the owner told you in chat that it is done. The item shows that it was marked done through an agent. Only a to-do can be marked done. A bridge worker session is refused, because a worker has no owner beside it. A review stays on the inbox page. The response is the item\'s summary.')]
final readonly class InboxDoneTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_done';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private WorkerSessionGuard $workers,
        private MarkInboxItemDoneHandler $done,
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
     * @param string $itemId the id of the to-do
     *
     * @return InboxItemListSummary
     */
    public function __invoke(string $itemId): array
    {
        $this->gate->requireEnabled();

        try {
            $item = $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE);
            $this->workers->requireNotWorker($item->project);

            return $this->payload->forListItem(($this->done)(new MarkInboxItemDoneCommand($item, throughAgent: true)));
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The item could not be marked done. The error has been logged.', previous: $e);
        }
    }
}
