<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\ListCardEventsCommand;
use App\Module\Board\Command\ListCardEventsHandler;
use App\Module\Board\Command\ListCardsHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * Reads one page of a card's history.
 *
 * @phpstan-import-type CardEventSummary from CardEventPayload
 */
#[McpTool(name: self::NAME, description: 'Read the history of one card, newest first: when it was created, each move between columns with who made it and why, and each action the board automation took on it. The response is paginated: pass page to walk further, and keep going while hasMore is true. perPage defaults to 50, with a maximum of 100. Each event carries kind (created, moved, fix-requested, stopped, ready-to-merge, synced, run-finished, paused, pause-released, verdict, review-overridden, override-ended, document-verdict, verdict-failed, auto-approved, approval-dismissed, human-review-changed or value-changed), occurredAt, and actor with its kind (human, agent, reviewer or system) and the name of the person behind it, or null. from and to name a column by id, slug and label: a move sets both, a creation sets to alone, and every other kind sets neither. cause says why the app moved the card on its own, such as a merged pull request, or is null. pullRequest comes from an automation action or an agent review override entry, reason from a fix-requested or stopped one on a pull request, or the reason code of a pause, and neither from a stopped event of a run that a workflow rule stopped, and run holds the whole record of a finished run. pause holds the kind of the pause (rule, retries, work-limit or work-stopped) and the ruleId that set it, on a paused or pause-released event only. On a pause-released event, pause also holds releaseReason, the code that says why the pause ended, such as facts-changed or released-by-person. releaseReason is null on a paused event and on an older event. override holds the approver and the headSha of the pull request head on a review-overridden event, and the headSha on an override-ended event; approver is null there. document holds documentId, title, rule, verdict, withdrawn and refusal, on a document-verdict or verdict-failed event only. A document-verdict event is a verdict on a design document of the card: verdict is approved, changes-requested or withdrawn, rule names the workflow rule that wrote it or is null for a person, and withdrawn names the verdict that a withdrawal took back. A verdict-failed event means a rule tried to approve a document and the app refused: refusal is a short code such as verdict_stale, and the other keys of document are null. humanReview holds value, cause and ruleId, on a human-review-changed event only: value is true when the switch went on, cause is person, agent, rule or rollout, and ruleId names the workflow rule that set it, or is null. valueChange holds key, value and previous, on a value-changed event only: it is the key of the card value that changed, the new value, and the value before, which is null when the key was unset. The app writes paused, and a person or the app writes pause-released. The history starts empty, so a change made before it began has no event. Pass the cardId or the number to name the card, never both.')]
final readonly class CardGetHistoryTool
{
    public const string NAME = 'card_get_history';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ListCardEventsHandler $listEvents,
        private CardEventPayload $payload,
    ) {
    }

    /**
     * @param string|null $cardId  the id of the card to read, from card_list or card_create; pass it or number, never both
     * @param int|null    $number  the card number, the short label that counts from 1 inside this project; pass it instead of cardId
     * @param int         $page    the 1-based page to read
     * @param int         $perPage how many events to return per page
     *
     * @return array{events: list<CardEventSummary>, page: int, perPage: int, total: int, hasMore: bool}
     */
    public function __invoke(?string $cardId = null, #[Schema(minimum: 1)] ?int $number = null, int $page = 1, int $perPage = ListCardsHandler::DEFAULT_PER_PAGE): array
    {
        try {
            $view = ($this->listEvents)(new ListCardEventsCommand(
                $this->subjects->requireCardByIdOrNumber($cardId, $number, McpBoundProjectVoter::CARD_READ),
                $page,
                $perPage,
            ));

            return [
                'events' => $this->payload->forEvents($view->events),
                'page' => $view->page,
                'perPage' => $view->perPage,
                'total' => $view->total,
                'hasMore' => $view->hasMore,
            ];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The card history could not be read. The error has been logged.', previous: $e);
        }
    }
}
