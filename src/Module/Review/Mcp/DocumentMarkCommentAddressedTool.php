<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Mcp\ResolvesBoundProject;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Module\Review\Command\MarkCommentIdsAddressedCommand;
use App\Module\Review\Command\MarkCommentIdsAddressedHandler;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Pending → Addressed, and nothing else. Addressed is the agent's claim that it
 * acted; Resolved is the human agreeing the thread is finished, so no MCP tool
 * writes it.
 */
#[McpTool(name: 'document_mark_comment_addressed', description: 'Mark document-review comment threads as addressed after acting on them. Accepts the root comment ids returned by document_get_review, and only ids from the document\'s current version — revising a document mints new comment rows, so re-read the review after document_revise. Ids that are unknown, superseded by a newer version, already addressed, already resolved, or point at a reply rather than a thread root are skipped, not fatal.')]
final readonly class DocumentMarkCommentAddressedTool
{
    use ResolvesBoundProject;

    public function __construct(
        private MarkCommentIdsAddressedHandler $markCommentsAddressed,
        private AuthenticatedProjectResolver $projectResolver,
    ) {
    }

    /**
     * `string[]` not `list<string>`: the SDK infers a parameter's JSON-schema
     * `items` from the docblock type and parses only the `T[]` and `array<T>`
     * spellings, so `list<string>` publishes an array of anything.
     *
     * @param string[] $commentIds root comment ids from document_get_review
     *
     * @return array{addressed: list<string>, skipped: list<array{id: string, reason: string}>}
     */
    public function __invoke(array $commentIds): array
    {
        try {
            // Rejected up front rather than per id: an unbound token is a setup
            // mistake, and letting the handler absorb it would report every
            // id as not found instead.
            $project = $this->requireBoundProject($this->projectResolver);

            $result = ($this->markCommentsAddressed)(new MarkCommentIdsAddressedCommand($project, array_values($commentIds)));
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The comments could not be marked as addressed. The error has been logged.', previous: $e);
        }

        return ['addressed' => $result->addressed, 'skipped' => $result->skipped];
    }
}
