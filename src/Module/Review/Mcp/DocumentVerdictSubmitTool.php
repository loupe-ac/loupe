<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Service\WorkerSession;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records the verdict of the owner on the newest version of a document.
 */
#[McpTool(name: 'document_verdict_submit', description: 'Record the verdict of the owner on a document, for the owner\'s account. Call it only after the owner gave the verdict in the chat. Never give a verdict on your own judgement. The verdict is approved or changes-requested. An approval moves the card of the document on. A verdict on a draft stops its agent review. Pass versionNumber, the version you showed the owner. A newer version is refused. Take expectedVerdictId from verdictId in document_get_review. Pass null when verdictId is null. A newer verdict is refused. Approval needs an answer to every decision. Changes-requested needs a note or an open comment. The review page shows that an agent recorded the verdict. A bridge worker is refused.')]
final readonly class DocumentVerdictSubmitTool
{
    public function __construct(
        private ReviewSubjectResolver $subjects,
        private WorkerSession $workers,
        private SubmitReviewHandler $handler,
        private DocumentToolErrors $errors,
        private Security $security,
    ) {
    }

    /**
     * @param string  $documentId        the UUID of the document
     * @param int     $versionNumber     the version you showed the owner
     * @param string  $verdict           approved or changes-requested
     * @param ?string $expectedVerdictId the verdictId from document_get_review, or null when that is null
     * @param ?string $note              the note of the owner for the verdict
     *
     * @return array{verdictId: string, verdict: string, documentStatus: string}
     */
    public function __invoke(
        string $documentId,
        #[Schema(minimum: 1)]
        int $versionNumber,
        #[Schema(enum: ['approved', 'changes-requested'])]
        string $verdict,
        ?string $expectedVerdictId,
        ?string $note = null,
    ): array {
        $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

        try {
            if ($this->workers->currentCallIsWorker($document->project)) {
                throw new ToolCallException('A bridge worker cannot record a verdict for the owner. Ask the owner with inbox_ask.');
            }

            $user = $this->security->getUser();
            $user instanceof User || throw new \LogicException('An MCP call always authenticates a user.');
            $review = ($this->handler)(new SubmitReviewCommand(
                reviewer: $user,
                document: $document,
                verdict: $verdict,
                versionNumber: $versionNumber,
                note: $note,
                expectedReviewId: $expectedVerdictId,
                throughAgent: true,
            ));

            return ['verdictId' => (string) $review->id, 'verdict' => $review->verdict->value, 'documentStatus' => $document->status->value];
        } catch (DomainErrors $e) {
            throw $this->errors->forDocument($e, $document, ['expectedReviewId' => 'expectedVerdictId']);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The verdict could not be recorded. The error has been logged.', previous: $e);
        }
    }
}
