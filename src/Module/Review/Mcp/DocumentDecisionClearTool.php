<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Service\WorkerSession;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Clears the chosen options of one decision block for the owner.
 */
#[McpTool(name: 'document_decision_clear', description: 'Clear the chosen options of a decision block of a document, for the owner\'s account. Call it only after the owner asked for it in the chat. Never clear an answer on your own judgement. Pass versionNumber, the version you showed the owner. A newer version is refused. The note that the owner wrote stays. A bridge worker is refused. The response says whether the answer changed.')]
final readonly class DocumentDecisionClearTool
{
    public function __construct(
        private ReviewSubjectResolver $subjects,
        private WorkerSession $workers,
        private SaveDecisionAnswerHandler $handler,
        private DocumentToolErrors $errors,
        private Security $security,
    ) {
    }

    /**
     * @param string $documentId    the UUID of the document
     * @param int    $versionNumber the version you showed the owner
     * @param string $decisionId    the id of the decision block
     *
     * @return array{decisionId: string, changed: bool, cleared: bool}
     */
    public function __invoke(
        string $documentId,
        #[Schema(minimum: 1)]
        int $versionNumber,
        string $decisionId,
    ): array {
        $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

        try {
            if ($this->workers->currentCallIsWorker($document->project)) {
                throw new ToolCallException('A bridge worker cannot clear a decision for the owner. Ask the owner with inbox_ask.');
            }

            $user = $this->security->getUser();
            $result = ($this->handler)(new SaveDecisionAnswerCommand(
                document: $document,
                decisionId: $decisionId,
                displayedVersionNumber: $versionNumber,
                optionIndexes: [],
                note: null,
                clear: true,
                answeredBy: $user instanceof User ? $user : throw new \LogicException('An MCP call always authenticates a user.'),
                throughAgent: true,
                requireLatestVersion: true,
                keepNote: true,
            ));

            return ['decisionId' => $decisionId, 'changed' => $result->changed, 'cleared' => $result->cleared];
        } catch (DomainErrors $e) {
            throw $this->errors->forDocument($e, $document);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The decision could not be cleared. The error has been logged.', previous: $e);
        }
    }
}
