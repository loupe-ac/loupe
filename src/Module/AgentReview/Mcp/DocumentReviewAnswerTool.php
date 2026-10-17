<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Mcp;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Command\AnswerDocumentReviewCommand;
use App\Module\AgentReview\Command\AnswerDocumentReviewHandler;
use App\Module\AgentReview\Entity\DocumentReviewAnswer;
use App\Module\AgentReview\Entity\DocumentReviewFindingAnswer;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Module\Review\Mcp\ReviewSubjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[McpTool(name: self::NAME, description: 'Answer each finding of the latest agent review that a card got for a draft design document. A review of another card that links the same document does not count. Only a running design fix worker of the card can call it: the call must come from the loupe CLI session of a worker run of the product-design-revise or tech-design-revise work on this card. Pass the cardId, the documentId and one answer for each finding of the latest review, counted from 0 in the order document_get_review lists them. An answer is fixed, not-changed with a reason, or to-owner for a finding that only the owner can settle. A finding that needs a person always takes to-owner. Call it once, after you revise the draft. A review that already has its answers refuses a second call.')]
final readonly class DocumentReviewAnswerTool
{
    public const string NAME = 'document_review_answer';

    public const int MAX_ANSWERS = 200;
    public const int MAX_REASON_LENGTH = 4000;

    public const array ANSWER_ITEM = [
        'type' => 'object',
        'properties' => [
            'index' => ['type' => 'integer', 'minimum' => 0, 'description' => 'the position of the finding in the latest review, counted from 0'],
            'answer' => ['type' => 'string', 'enum' => ['fixed', 'not-changed', 'to-owner']],
            'reason' => ['type' => 'string', 'description' => 'why the finding stays as it is, required with not-changed, and optional with the other answers'],
        ],
        'required' => ['index', 'answer'],
    ];

    private const array MESSAGES = [
        AnswerDocumentReviewHandler::NO_FIX_RUN => 'document_review_answer takes answers only from a running design fix worker of this card. The call names no such run. A fix worker sends its session with every call through the loupe CLI.',
        AnswerDocumentReviewHandler::WORK_WITHDRAWN => 'This work was withdrawn.',
        AnswerDocumentReviewHandler::NOT_LINKED => 'documentId must name a document that the card links.',
        AnswerDocumentReviewHandler::NO_REVIEW => 'The document has no agent review of this card to answer.',
        AnswerDocumentReviewHandler::ALREADY_ANSWERED => 'The latest agent review of the document for this card already has its answers.',
        AnswerDocumentReviewHandler::UNKNOWN_FINDING => 'An answer names a finding that the latest review does not have.',
        AnswerDocumentReviewHandler::DUPLICATE_ANSWER => 'Two answers name the same finding.',
        AnswerDocumentReviewHandler::MISSING_ANSWER => 'Answer every finding of the latest review.',
        AnswerDocumentReviewHandler::REASON_REQUIRED => 'An answer of not-changed needs a reason.',
        AnswerDocumentReviewHandler::OWNER_FINDING => 'A finding that needs a person takes the answer to-owner, with a decision fence in the draft.',
    ];

    public function __construct(
        private BoardSubjectResolver $cards,
        private ReviewSubjectResolver $documents,
        private AnswerDocumentReviewHandler $answerReview,
        private RequestStack $requests,
    ) {
    }

    /**
     * @param string         $cardId     the id of the card that the document belongs to
     * @param string         $documentId the id of the draft document that you revised
     * @param array<mixed>[] $answers    one answer for each finding of the latest review, an empty list when it had none
     *
     * @return array{reviewId: string, answered: int}
     */
    public function __invoke(
        string $cardId,
        string $documentId,
        #[Schema(items: self::ANSWER_ITEM, maxItems: self::MAX_ANSWERS)] array $answers,
    ): array {
        try {
            $card = $this->cards->requireCard($cardId, McpBoundProjectVoter::CARD_WRITE);
            $document = $this->documents->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $review = ($this->answerReview)(new AnswerDocumentReviewCommand(
                card: $card,
                document: $document,
                sessionId: $this->sessionId(),
                answers: $this->answers($answers),
            ));

            return ['reviewId' => (string) $review->id, 'answered' => \count($review->answers ?? [])];
        } catch (DomainErrors $e) {
            throw new ToolCallException(self::MESSAGES[array_first($e->errors)] ?? 'The answers were refused. The error has been logged.', previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The answers could not be stored. The error has been logged.', previous: $e);
        }
    }

    private function sessionId(): ?Uuid
    {
        $session = $this->requests->getCurrentRequest()?->headers->get(AgentRunCause::SESSION_HEADER);

        return null !== $session && Uuid::isValid($session) ? Uuid::fromString($session) : null;
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<DocumentReviewFindingAnswer>
     */
    private function answers(array $items): array
    {
        if (\count($items) > self::MAX_ANSWERS) {
            throw new ToolCallException(\sprintf('A call takes at most %d answers.', self::MAX_ANSWERS));
        }

        $answers = [];
        foreach (array_values($items) as $position => $item) {
            $field = static fn (string $name): string => \sprintf('answers[%d].%s', $position, $name);
            if (!\is_array($item)) {
                throw new ToolCallException(\sprintf('answers[%d] must be an object.', $position));
            }

            $index = $item['index'] ?? null;
            if (!\is_int($index) || $index < 0) {
                throw new ToolCallException(\sprintf('%s must be a finding position from 0.', $field('index')));
            }
            $answer = $item['answer'] ?? null;
            $parsedAnswer = \is_string($answer) ? DocumentReviewAnswer::tryFrom($answer) : null;
            if (null === $parsedAnswer) {
                throw new ToolCallException(\sprintf('%s must be one of: %s.', $field('answer'), implode(', ', array_map(static fn (DocumentReviewAnswer $case): string => $case->value, DocumentReviewAnswer::cases()))));
            }
            $reason = $item['reason'] ?? null;
            if (null !== $reason && (!\is_string($reason) || mb_strlen($reason) > self::MAX_REASON_LENGTH)) {
                throw new ToolCallException(\sprintf('%s must be a string of at most %d characters.', $field('reason'), self::MAX_REASON_LENGTH));
            }

            $answers[] = new DocumentReviewFindingAnswer($index, $parsedAnswer, null === $reason ? null : trim($reason));
        }

        return $answers;
    }
}
