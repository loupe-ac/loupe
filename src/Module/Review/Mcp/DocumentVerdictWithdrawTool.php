<?php

declare(strict_types=1);

namespace App\Module\Review\Mcp;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Module\Review\Service\WorkerSession;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Withdraws the newest verdict on the newest version of a document for the owner.
 */
#[McpTool(name: 'document_verdict_withdraw', description: 'Withdraw the newest verdict on a document, for the owner\'s account. Call it only after the owner asked for it in the chat. Never withdraw a verdict on your own judgement. Pass versionNumber, the version you showed the owner. A newer version is refused. Take verdictId from verdictId in document_get_review. A newer verdict is refused. The review page shows that an agent recorded the withdrawal. A bridge worker is refused.')]
final readonly class DocumentVerdictWithdrawTool
{
    public function __construct(
        private ReviewSubjectResolver $subjects,
        private WorkerSession $workers,
        private UndoVerdictHandler $handler,
        private DocumentToolErrors $errors,
        private Security $security,
    ) {
    }

    /**
     * @param string $documentId    the UUID of the document
     * @param int    $versionNumber the version you showed the owner
     * @param string $verdictId     the verdictId from document_get_review
     *
     * @return array{verdictId: string, documentStatus: string}
     */
    public function __invoke(
        string $documentId,
        #[Schema(minimum: 1)]
        int $versionNumber,
        string $verdictId,
    ): array {
        $document = $this->subjects->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

        try {
            if ($this->workers->currentCallIsWorker($document->project)) {
                throw new ToolCallException('A bridge worker cannot withdraw a verdict for the owner. Ask the owner with inbox_ask.');
            }

            $user = $this->security->getUser();
            $withdrawal = ($this->handler)(new UndoVerdictCommand(
                document: $document,
                actor: $user instanceof User ? $user : throw new \LogicException('An MCP call always authenticates a user.'),
                reviewId: $verdictId,
                expectedVersionNumber: $versionNumber,
                throughAgent: true,
            ));

            return ['verdictId' => (string) $withdrawal->id, 'documentStatus' => $document->status->value];
        } catch (DomainErrors $e) {
            throw $this->errors->forDocument($e, $document, ['reviewId' => 'verdictId']);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The verdict could not be withdrawn. The error has been logged.', previous: $e);
        }
    }
}
