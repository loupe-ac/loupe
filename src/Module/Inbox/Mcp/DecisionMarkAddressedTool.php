<?php

declare(strict_types=1);

namespace App\Module\Inbox\Mcp;

use App\Exception\DomainErrors;
use App\Mcp\FlagGatedToolInterface;
use App\Module\Bridge\Service\CallerRun;
use App\Module\Inbox\Command\MarkInboxDecisionsAddressedCommand;
use App\Module\Inbox\Command\MarkInboxDecisionsAddressedHandler;
use App\Module\Inbox\Entity\InboxDecision;
use App\Module\Inbox\Install\InboxInstallFlags;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Marks changed decisions as acted on. */
#[McpTool(name: self::NAME, description: 'Mark changed decisions of a card as acted on, after you redid the work that each change affects. Pass each decision as an object with its id and its changedAt, exactly as decision_list gave them, and your sessionId, the first set value of $LOUPE_SESSION_ID, $CLAUDE_CODE_SESSION_ID and $CODEX_THREAD_ID. A session id that is not your own is refused. A decision that the owner changed again after you read it is refused, so call decision_list again and act on the new change. An id of another project, an id with no change and an id of another card than the card of your run are refused, and nothing is marked then. A change that is marked already stays as it is. The card then stops asking for a fix round for these changes. The response lists the ids this call marked.')]
final readonly class DecisionMarkAddressedTool implements FlagGatedToolInterface
{
    public const string NAME = 'decision_mark_addressed';
    public const int MAX_IDS = 50;

    public function __construct(
        private InboxFlagGate $gate,
        private InboxSubjectResolver $subjects,
        private CallerSessionGuard $caller,
        private CallerRun $callerRun,
        private MarkInboxDecisionsAddressedHandler $mark,
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
     * @param list<array{id: string, changedAt: string}> $decisions the id and the changedAt that decision_list gave you for each decision
     * @param string                                     $sessionId the id of your agent session, the first set value of $LOUPE_SESSION_ID, $CLAUDE_CODE_SESSION_ID and $CODEX_THREAD_ID
     *
     * @return array{addressed: list<string>}
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'changedAt' => ['type' => 'string']], 'required' => ['id', 'changedAt']], minItems: 1, maxItems: self::MAX_IDS)]
        array $decisions,
        string $sessionId,
    ): array {
        $this->gate->requireEnabled();

        try {
            $project = $this->subjects->requireProject();
            $session = $this->subjects->requireUuid($sessionId, 'session ID');
            $this->caller->requireOwnSession($session);

            if ([] === $decisions || \count($decisions) > self::MAX_IDS || array_any($decisions, static fn (mixed $row): bool => !\is_array($row) || !\is_string($row['id'] ?? null) || !\is_string($row['changedAt'] ?? null))) {
                throw new ToolCallException(\sprintf('decisions must hold 1 to %d objects, each with an id and a changedAt.', self::MAX_IDS));
            }
            $versions = [];
            foreach ($decisions as $row) {
                $uuid = $this->subjects->requireUuid($row['id'], 'decision ID');
                $versions[(string) $uuid] = $row['changedAt'];
            }
            $marked = ($this->mark)(new MarkInboxDecisionsAddressedCommand(
                project: $project,
                versions: $versions,
                sessionId: $session,
                credentialRun: $this->callerRun->workerRun($project),
            ));

            return ['addressed' => array_map(static fn (InboxDecision $decision): string => (string) $decision->id, $marked)];
        } catch (DomainErrors $e) {
            throw $this->errorMessages->forAgent($e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The decisions could not be marked. The error has been logged.', previous: $e);
        }
    }
}
