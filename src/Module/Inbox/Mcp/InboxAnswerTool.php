<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\AnswerInboxItemCommand;
use App\Module\Inbox\Command\AnswerInboxItemHandler;
use App\Module\Inbox\Form\RunInboxActionRequest;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Answers an inbox question for the owner.
 *
 * @phpstan-import-type InboxItemListSummary from InboxItemPayload
 */
#[McpTool(name: self::NAME, description: 'Answer an open inbox question for the owner, only after the owner told you the answer in chat. Never answer for the owner on your own judgement. Pass selectedOptions, the indexes of the options the owner chose, counting from 0, or answerText, or both, as the question allows. The item shows that it was answered through an agent. An answer is final: Loupe refuses a second answer for the same item. Loupe runs the option you pass for a workflow question at once. A bridge worker session is refused, because a worker has no owner beside it. A review is not a question. To record a review verdict the owner gave in chat, call document_verdict_submit. The response is the item\'s summary.')]
final readonly class InboxAnswerTool implements FlagGatedToolInterface
{
    public const string NAME = 'inbox_answer';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private WorkerSessionGuard $workers,
        private AnswerInboxItemHandler $answer,
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
     * @param string    $itemId          the id of the question
     * @param list<int> $selectedOptions the indexes of the options the owner chose, counting from 0
     * @param ?string   $answerText      the free text the owner gave
     *
     * @return InboxItemListSummary
     */
    public function __invoke(
        string $itemId,
        #[Schema(type: 'array', items: ['type' => 'integer', 'minimum' => 0])]
        array $selectedOptions = [],
        #[Schema(maxLength: RunInboxActionRequest::MAX_RESPONSE_LENGTH)]
        ?string $answerText = null,
    ): array {
        $this->gate->requireEnabled();

        try {
            $item = $this->subjects->requireItem($itemId, McpBoundProjectVoter::INBOX_ITEM_WRITE);
            $this->workers->requireNotWorker($item->project);

            return $this->payload->forListItem(($this->answer)(new AnswerInboxItemCommand(
                item: $item,
                selectedOptions: implode(',', $selectedOptions),
                answerText: $answerText ?? '',
                throughAgent: true,
            )));
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The item could not be answered. The error has been logged.', previous: $e);
        }
    }
}
