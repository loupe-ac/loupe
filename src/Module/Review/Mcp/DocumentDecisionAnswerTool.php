<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\SaveDecisionAnswerCommand;
use App\Module\Review\Command\SaveDecisionAnswerHandler;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Service\WorkerSession;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records the owner's answer to one decision block of a document.
 */
#[McpTool(name: 'document_decision_answer', description: 'Record the answer of the owner to a decision block of a document, for the owner\'s account. Call it only after the owner gave the answer in the chat. Never answer on your own judgement. Pass versionNumber, the version you showed the owner. A newer version is refused. Pass optionIndexes, the indexes of the options the owner chose, counting from 0. Leave note out to keep the note that the owner wrote. Pass an empty note to remove it. The review page shows that an agent recorded the answer. A bridge worker is refused. The response says whether the answer changed.')]
final readonly class DocumentDecisionAnswerTool
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
     * @param string    $documentId    the UUID of the document
     * @param int       $versionNumber the version you showed the owner
     * @param string    $decisionId    the id of the decision block
     * @param list<int> $optionIndexes the indexes of the options the owner chose, counting from 0
     * @param ?string   $note          the note of the owner; leave it out to keep the stored note, pass an empty string to remove it
     *
     * @return array{decisionId: string, changed: bool, cleared: bool}
     */
    public function __invoke(
        string $documentId,
        #[Schema(minimum: 1)]
        int $versionNumber,
        string $decisionId,
        #[Schema(type: 'array', items: ['type' => 'integer', 'minimum' => 0], minItems: 1)]
        array $optionIndexes,
        #[Schema(maxLength: DecisionAnswer::MAX_NOTE_LENGTH)]
        ?string $note = null,
    ): array {
        $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

        try {
            if ($this->workers->currentCallIsWorker($document->project)) {
                throw new ToolCallException('A bridge worker cannot answer a decision for the owner. Ask the owner with inbox_ask.');
            }

            $user = $this->security->getUser();
            $result = ($this->handler)(new SaveDecisionAnswerCommand(
                document: $document,
                decisionId: $decisionId,
                displayedVersionNumber: $versionNumber,
                optionIndexes: array_values($optionIndexes),
                note: $note,
                clear: false,
                answeredBy: $user instanceof User ? $user : throw new \LogicException('An MCP call always authenticates a user.'),
                throughAgent: true,
                requireLatestVersion: true,
                keepNote: null === $note,
            ));

            return ['decisionId' => $decisionId, 'changed' => $result->changed, 'cleared' => $result->cleared];
        } catch (DomainErrors $e) {
            throw $this->errors->forDocument($e, $document);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The decision could not be answered. The error has been logged.', previous: $e);
        }
    }
}
