<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\SiteReview\Command\MarkSiteReviewCommentIdsAddressedCommand;
use App\Module\SiteReview\Command\MarkSiteReviewCommentIdsAddressedHandler;
use App\Module\SiteReview\Mcp\SiteReviewSubjectResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * The agent's only write on feedback: Pending to Addressed. Resolved stays with
 * the human, and no MCP tool reaches the resolve route.
 */
#[McpTool(name: self::NAME, description: 'Mark feedback items as addressed after you fix them. Feedback is what a reviewer leaves on a page with the live-review widget, and each item belongs to a card. Pass the feedback ids from feedback_list or card_get. Only the feedback of the project bound to your MCP token is reachable. An item that is unknown, already addressed or resolved is skipped, not fatal. The skip reason is best-effort: the write settles the status, and the reason comes from a separate read that can be stale when another writer changes the same item at that moment.')]
final readonly class FeedbackMarkAddressedTool
{
    public const string NAME = 'feedback_mark_addressed';

    public function __construct(
        private SiteReviewSubjectResolver $subjects,
        private MarkSiteReviewCommentIdsAddressedHandler $markCommentsAddressed,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK parses only the `T[]` and
     * `array<T>` spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $feedbackIds feedback ids from feedback_list or card_get
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $feedbackIds): array
    {
        try {
            // An unbound token is refused once, even for an empty batch.
            $project = $this->subjects->requireProject();

            $result = ($this->markCommentsAddressed)(new MarkSiteReviewCommentIdsAddressedCommand($project, array_values($feedbackIds)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The feedback could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $result->addressed, 'skipped' => $result->skipped];
    }
}
