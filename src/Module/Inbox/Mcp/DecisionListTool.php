<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Inbox\Command\ListInboxDecisionsCommand;
use App\Module\Inbox\Command\ListInboxDecisionsHandler;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Reads the decisions of a card that the owner changed and that no worker acted on yet.
 *
 * @phpstan-type OpenDecisionChange array{id: string, label: string, originalChoice: string, changedChoice: string, note: ?string, changedAt: string}
 */
#[McpTool(name: self::NAME, description: 'List the decisions of a card that the owner changed and that nobody has acted on yet. Call it at the start of a fix round, and when a card asks for a fix round with the reason decision-changed. Each row holds id, label, originalChoice (what you or Loupe chose), changedChoice (what the owner chose, which can be the owner\'s own words), note (the owner\'s reason, or null) and changedAt. Redo the work that each change affects, then call decision_mark_addressed with the ids. A change that lands after you listed is not in your list, so mark only the ids you read. The list is empty when nothing is open.')]
final readonly class DecisionListTool implements FlagGatedToolInterface
{
    public const string NAME = 'decision_list';

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private ListInboxDecisionsHandler $list,
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
     * @param string $cardId the id of a card of this project
     *
     * @return array{decisions: list<OpenDecisionChange>}
     */
    public function __invoke(string $cardId): array
    {
        $this->gate->requireEnabled();

        try {
            $rows = ($this->list)(new ListInboxDecisionsCommand(
                project: $this->subjects->requireProject(),
                cardId: $this->subjects->requireUuid($cardId, 'card ID'),
            ));

            return ['decisions' => array_map(static fn (InboxDecision $decision): array => [
                'id' => (string) $decision->id,
                'label' => $decision->label,
                'originalChoice' => $decision->choice,
                'changedChoice' => $decision->changedChoice ?? '',
                'note' => $decision->changedNote,
                'changedAt' => ($decision->changedAt ?? throw new \LogicException('An open change has a time.'))->format(\DATE_ATOM),
            ], $rows)];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The decisions could not be read. The error has been logged.', previous: $e);
        }
    }
}
