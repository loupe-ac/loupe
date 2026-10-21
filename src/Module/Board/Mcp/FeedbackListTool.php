<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Module\Board\Command\ListFeedbackCommand;
use App\Module\Board\Command\ListFeedbackHandler;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;

/**
 * Lists the feedback of the whole project, each item with the card it belongs to.
 *
 * @phpstan-import-type FeedbackAnchorSummary from CardPayload
 * @phpstan-import-type ScreenshotSize from CardPayload
 *
 * @phpstan-type FeedbackListItem array{id: string, url: ?string, surface: string, native: ?array<string, mixed>, screenshot: ?ScreenshotSize, anchors: list<FeedbackAnchorSummary>, body: string, hasDrawing: bool, status: string, context: ?string, createdAt: string, cardId: ?string, number: ?int, title: ?string}
 */
#[McpTool(name: self::NAME, description: 'List the feedback of the project bound to your MCP token. Feedback is what a reviewer leaves on a page with the live-review widget, and each item belongs to a card. Each item has its id, the page url, the anchors (the elements it points at), the body, hasDrawing, the status, the context (what a preview page said it served, or null), createdAt, and the cardId, number and title of its card. Each item also has surface (web or ios-simulator), native (what a native note says about the app, device, screen and element, or null) and screenshot (the width and height of the marked image of the note, or null). Returns the pending items by default. Pass status to read the addressed or the resolved ones, or all. Pass cardId to list the feedback of one card only. The answer carries the screenshots of the pending notes as images after the JSON, newest note first, at most ten. Pass imageOffset to skip that many images. nextImageOffset is the offset of the next ten, or null when no image remains. Fix each item, then mark it with feedback_mark_addressed.')]
final readonly class FeedbackListTool
{
    public const string NAME = 'feedback_list';

    public function __construct(
        private BoardSubjectResolver $subjects,
        private ListFeedbackHandler $listFeedback,
        private FeedbackScreenshots $screenshots,
    ) {
    }

    /**
     * The feedback is the structured result, and the images of its pending notes follow its JSON.
     *
     * @param string|null $status      which feedback to return: pending (the default), addressed, resolved, or all
     * @param string|null $cardId      only the feedback of this card, from card_get or card_list
     * @param int         $imageOffset how many images to skip, from the nextImageOffset of the last answer
     */
    public function __invoke(?string $status = null, ?string $cardId = null, #[Schema(minimum: 0)] int $imageOffset = 0): CallToolResult
    {
        try {
            $filter = match ($status) {
                null, 'pending' => SiteReviewCommentStatus::Pending,
                'all' => null,
                'addressed' => SiteReviewCommentStatus::Addressed,
                'resolved' => SiteReviewCommentStatus::Resolved,
                default => throw new ToolCallException(\sprintf('Unknown status "%s". Use pending, addressed, resolved or all.', $status)),
            };

            $view = ($this->listFeedback)(new ListFeedbackCommand(
                $this->subjects->requireProject(),
                $filter,
                null === $cardId ? null : $this->subjects->requireCard($cardId, McpBoundProjectVoter::CARD_READ),
            ));
            $links = $view->links;
            $sizes = $view->sizes;
            $images = $this->screenshots->page($view->feedback, $imageOffset);

            return $images->resultOf([
                'feedback' => array_map(
                    static function (SiteReviewComment $comment) use ($links, $sizes): array {
                        $card = ($links[(string) $comment->id] ?? null)?->card;

                        return [
                            ...CardPayload::feedback($comment, $sizes[(string) $comment->id] ?? null),
                            'cardId' => null === $card ? null : (string) $card->id,
                            'number' => $card?->number,
                            'title' => $card?->title,
                        ];
                    },
                    $view->feedback,
                ),
                'nextImageOffset' => $images->remaining > 0 ? $imageOffset + \count($images->images) : null,
            ]);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The feedback could not be read. The error has been logged.', previous: $e);
        }
    }
}
