<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Mcp;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Command\SubmitDocumentReviewCommand;
use App\Module\AgentReview\Command\SubmitDocumentReviewHandler;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Mcp\AgentRunCause;
use App\Module\Board\Mcp\BoardSubjectResolver;
use App\Module\Review\Mcp\ReviewSubjectResolver;
use App\Security\McpBoundProjectVoter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[McpTool(name: self::NAME, description: 'Submit the review of a draft design document of a card. Only a running design review worker of the card can call it: the call must come from the loupe CLI session of a worker run of the design-review work on this card. Pass the cardId, the documentId of a draft that the card links, the versionNumber that you read, a summary, and the findings. Each finding has a severity (important, nit or pre-existing), a title and a body, and names no file. A finding can set needsPerson, for a gap that a worker cannot settle from the design and the code alone and that the owner must decide. The review fails when a finding without needsPerson has a severity that the project counts as failing, important by default.')]
final readonly class DocumentReviewSubmitTool
{
    public const string NAME = 'document_review_submit';

    public const int MAX_FINDINGS = 200;
    public const int MAX_SUMMARY_LENGTH = 10000;
    public const int MAX_TITLE_LENGTH = 200;
    public const int MAX_BODY_LENGTH = 4000;

    public const array FINDING_ITEM = [
        'type' => 'object',
        'properties' => [
            'needsPerson' => ['type' => 'boolean', 'description' => 'true on a finding that a worker cannot settle from the design and the code alone, because the design is unclear, contradicts itself or looks wrong against the code. Leave it out for a finding that the fix worker can fix'],
            'severity' => ['type' => 'string', 'enum' => ['important', 'nit', 'pre-existing']],
            'title' => ['type' => 'string', 'description' => 'one line that names the problem'],
            'body' => ['type' => 'string', 'description' => 'what is wrong and how to fix it'],
        ],
        'required' => ['severity', 'title', 'body'],
    ];

    private const array MESSAGES = [
        SubmitDocumentReviewHandler::NO_REVIEW_RUN => 'document_review_submit takes a review only from a running design review worker of this card. The call names no such run. A review worker sends its session with every call through the loupe CLI.',
        SubmitDocumentReviewHandler::NOT_LINKED => 'documentId must name a document that the card links.',
        SubmitDocumentReviewHandler::NOT_DRAFT => 'The document is not a draft, so it takes no agent review.',
        SubmitDocumentReviewHandler::NO_SUCH_VERSION => 'versionNumber must name a version of the document.',
    ];

    public function __construct(
        private BoardSubjectResolver $cards,
        private ReviewSubjectResolver $documents,
        private SubmitDocumentReviewHandler $submitReview,
        private RequestStack $requests,
    ) {
    }

    /**
     * @param string         $cardId        the id of the card that the document belongs to
     * @param string         $documentId    the id of the draft document under review
     * @param int            $versionNumber the number of the version that you read
     * @param string         $summary       a short summary of the review
     * @param array<mixed>[] $findings      the findings, an empty list when the review found nothing
     *
     * @return array{reviewId: string, conclusion: string, current: bool} current says whether $versionNumber is still the newest version of the document
     */
    public function __invoke(
        string $cardId,
        string $documentId,
        #[Schema(minimum: 1)] int $versionNumber,
        string $summary,
        #[Schema(items: self::FINDING_ITEM, maxItems: self::MAX_FINDINGS)] array $findings,
    ): array {
        try {
            $card = $this->cards->requireCard($cardId, McpBoundProjectVoter::CARD_WRITE);
            $document = $this->documents->requireDocument($documentId, McpBoundProjectVoter::DOCUMENT_WRITE);

            $summary = trim($summary);
            if ('' === $summary) {
                throw new ToolCallException('summary must not be empty.');
            }
            if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
                throw new ToolCallException(\sprintf('summary must not be longer than %d characters.', self::MAX_SUMMARY_LENGTH));
            }

            $review = ($this->submitReview)(new SubmitDocumentReviewCommand(
                card: $card,
                document: $document,
                sessionId: $this->sessionId(),
                versionNumber: $versionNumber,
                summary: $summary,
                findings: $this->findings($findings),
            ));

            return [
                'reviewId' => (string) $review->id,
                'conclusion' => $review->conclusion->value,
                'current' => $document->currentVersion()->versionNumber === $review->versionNumber,
            ];
        } catch (DomainErrors $e) {
            throw new ToolCallException(self::MESSAGES[array_first($e->errors)] ?? 'The review was refused. The error has been logged.', previous: $e);
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The review could not be stored. The error has been logged.', previous: $e);
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
     * @return list<AgentReviewFinding>
     */
    private function findings(array $items): array
    {
        if (\count($items) > self::MAX_FINDINGS) {
            throw new ToolCallException(\sprintf('A review takes at most %d findings.', self::MAX_FINDINGS));
        }

        $findings = [];
        foreach (array_values($items) as $index => $item) {
            $field = static fn (string $name): string => \sprintf('findings[%d].%s', $index, $name);
            if (!\is_array($item)) {
                throw new ToolCallException(\sprintf('findings[%d] must be an object.', $index));
            }

            $needsPerson = $item['needsPerson'] ?? false;
            if (!\is_bool($needsPerson)) {
                throw new ToolCallException(\sprintf('%s must be true or false.', $field('needsPerson')));
            }
            $severity = $item['severity'] ?? null;
            $parsedSeverity = \is_string($severity) ? AgentReviewSeverity::tryFrom($severity) : null;
            if (null === $parsedSeverity) {
                throw new ToolCallException(\sprintf('%s must be one of: %s.', $field('severity'), implode(', ', array_map(static fn (AgentReviewSeverity $case): string => $case->value, AgentReviewSeverity::cases()))));
            }
            $title = \is_string($item['title'] ?? null) ? trim($item['title']) : '';
            if ('' === $title || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
                throw new ToolCallException(\sprintf('%s must not be empty, and at most %d characters.', $field('title'), self::MAX_TITLE_LENGTH));
            }
            $body = $item['body'] ?? null;
            if (!\is_string($body) || mb_strlen($body) > self::MAX_BODY_LENGTH) {
                throw new ToolCallException(\sprintf('%s must be a string of at most %d characters.', $field('body'), self::MAX_BODY_LENGTH));
            }

            $findings[] = new AgentReviewFinding(null, null, null, $parsedSeverity, $title, trim($body), AgentReviewCategory::Spec, $needsPerson);
        }

        return $findings;
    }
}
