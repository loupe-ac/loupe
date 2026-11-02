<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Module\Review\Command\ShowReviewCommand;
use App\Module\Review\Command\ShowReviewHandler;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Fetch the current review state (verdict, status, comments, decisions) for a document.
 *
 * @phpstan-import-type ReviewPayload from ShowReviewHandler
 */
#[McpTool(name: 'document_get_review', description: 'Fetch the review state (verdict, status, threaded comments, answered decision blocks, and the agent reviews with their answers) for a document\'s current version. Every comment and reply reports whether an agent or a human wrote it, and a comment may carry a replacement for the text it quotes. A decision block reports its type: a single-choice block answers in selected, and a multiple-choice block answers in selections. verdictId is the id of the newest entry of the verdict log, a withdrawal included, and verdictThroughAgent says that an agent recorded it for the owner, and verdictThroughApiKey says that an API key did. The verdict tools take that verdictId. Each decision reports through_agent when an agent recorded its answer for the owner, and through_api_key when an API key did.')]
final readonly class DocumentGetReviewTool
{
    public function __construct(
        private ShowReviewHandler $showReview,
        private ReviewSubjectResolver $subjects,
    ) {
    }

    /**
     * @param string $documentId the UUID of the document whose review to retrieve
     *
     * `verdict` is null when no review has been submitted for the current version yet
     *
     * A comment's `status` is one of pending, addressed or resolved and belongs to the whole
     * thread, so only the root comment reports it
     *
     * Each `id` is the value document_reply_to_comment and document_mark_comment_addressed take
     *
     * `author` is agent or human — the class of writer, not an identity, so no name or address
     * of a human reviewer is reported
     *
     * `replacement` is what the reviewer wants the quoted passage to become: null means they
     * proposed no edit, an empty string means delete the passage, and any other value is the
     * text to put in its place. Only root comments carry it. Loupe never edits the document —
     * applying these is your job: rewrite the markdown and call document_revise. On a comment
     * with a replacement the `quote` is the verbatim selected span, so it can be substituted
     * as-is; on every other comment it is widened to whole words for readability and must not
     * be used for a find-and-replace
     *
     * An `orphaned: true` comment's quote no longer appears in the document, so its replacement
     * has no target and cannot be applied — reply to it instead of guessing
     *
     * `decisions` lists every decision block in the current version. `selected` is null while a
     * decision is unanswered, and otherwise the option as it read when the reviewer chose it
     *
     * `selected_index` points into the same entry's `options`, and is null when the chosen option
     * is no longer among them — `answered_at_version` then says which version it came from
     *
     * `type` is single or multiple. A multiple-choice block takes any number of answers, so it
     * reports null in `selected`, `selected_index`, `answered_at` and `answered_at_version`
     *
     * `selections` lists every recorded answer of either kind of block, each with the `option`
     * text, its `index` in `options`, and when and against which version it was chosen. Read it
     * for a multiple-choice block, and read it for a single-choice one too if you want one shape
     *
     * `note` is the text the reviewer wrote beside their choice, or null when they wrote none.
     * `updated_at` is when the answer was last saved, and is null for an unanswered decision.
     * A decision can carry a note with no option chosen, so read the note even when `selections`
     * is empty
     *
     * Each decision's `id` is the one the document declared in its fence, and it is permanent:
     * changing it in a revision discards the answer keyed to the old one
     *
     * `through_agent` is true when an agent recorded the answer for the owner, and false otherwise
     *
     * `verdictId` is the id of the newest entry of the verdict log on the current version, a
     * withdrawal included, or null while the log is empty. The verdict tools take it, so read
     * it again before you call one. `verdictThroughAgent` is true when an agent recorded that
     * entry for the owner, and false when the log is empty. `verdictThroughApiKey` says the same of an API key
     *
     * `agentReviews` lists each agent review of the document, newest first. Each carries the
     * `cardId` of the card it reviewed, the `versionNumber` that the reviewer read, its
     * `conclusion`, its `findings` with their `index`, and the `answer` of the fix round to
     * each finding, or null before it answers.
     * `answered` says whether the fix round has answered the review
     *
     * @return ReviewPayload
     */
    public function __invoke(string $documentId): array
    {
        try {
            $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_READ);

            return ($this->showReview)(new ShowReviewCommand($document));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be read. The error has been logged.', previous: $e);
        }
    }
}
