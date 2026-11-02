<?php

declare(strict_types=1);

namespace App\Module\Review\Controller\Api;

use App\Module\Review\Command\ShowReviewHandler;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The review of the current version of a document.
 *
 * @phpstan-import-type ReviewPayload from ShowReviewHandler
 */
#[OA\Schema(required: ['status', 'verdict', 'verdictId', 'verdictThroughAgent', 'note', 'version', 'comments', 'decisions', 'agentReviews', 'verdictThroughApiKey'])]
final readonly class ReviewResponse
{
    /** The top-level names of a review, in the order of its row. */
    public const array FIELDS = ['status', 'verdict', 'verdictId', 'verdictThroughAgent', 'note', 'version', 'comments', 'decisions', 'agentReviews', 'verdictThroughApiKey'];

    /**
     * @param list<ReviewCommentResponse>       $comments
     * @param list<ReviewDecisionResponse>      $decisions
     * @param list<DocumentAgentReviewResponse> $agentReviews
     */
    public function __construct(
        #[OA\Property(enum: ['in-review', 'approved', 'changes-requested', 'draft'])]
        public string $status,

        #[OA\Property(description: 'The verdict standing on the current version. Null also after a withdrawal.', nullable: true, enum: ['approved', 'changes-requested', null])]
        public ?string $verdict,

        #[OA\Property(description: 'The newest entry of the verdict log, a withdrawal included.', format: 'uuid', nullable: true)]
        public ?string $verdictId,

        #[OA\Property]
        public bool $verdictThroughAgent,

        #[OA\Property(nullable: true)]
        public ?string $note,

        #[OA\Property]
        public int $version,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ReviewCommentResponse::class)))]
        public array $comments,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: ReviewDecisionResponse::class)))]
        public array $decisions,

        #[OA\Property(description: 'Newest first.', type: 'array', items: new OA\Items(ref: new Model(type: DocumentAgentReviewResponse::class)))]
        public array $agentReviews,

        #[OA\Property(description: 'An API key recorded the verdict for the owner.')]
        public bool $verdictThroughApiKey,
    ) {
    }

    /** @param ReviewPayload $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            status: $payload['status'],
            verdict: $payload['verdict'],
            verdictId: $payload['verdictId'],
            verdictThroughAgent: $payload['verdictThroughAgent'],
            note: $payload['note'],
            version: $payload['version'],
            comments: array_map(static fn (array $comment): ReviewCommentResponse => new ReviewCommentResponse(
                id: $comment['id'],
                quote: $comment['quote'],
                body: $comment['body'],
                replacement: $comment['replacement'],
                author: $comment['author'],
                status: $comment['status'],
                orphaned: $comment['orphaned'],
                thread: array_map(static fn (array $reply): ReviewReplyResponse => new ReviewReplyResponse($reply['id'], $reply['quote'], $reply['body'], $reply['author'], $reply['orphaned']), $comment['thread']),
            ), $payload['comments']),
            decisions: array_map(static fn (array $decision): ReviewDecisionResponse => new ReviewDecisionResponse(
                id: $decision['id'],
                type: $decision['type'],
                options: $decision['options'],
                selected: $decision['selected'],
                selectedIndex: $decision['selected_index'],
                answeredAt: $decision['answered_at'],
                answeredAtVersion: $decision['answered_at_version'],
                note: $decision['note'],
                updatedAt: $decision['updated_at'],
                throughAgent: $decision['through_agent'],
                selections: array_map(static fn (array $selection): DecisionSelectionResponse => new DecisionSelectionResponse($selection['option'], $selection['index'], $selection['answered_at'], $selection['answered_at_version']), $decision['selections']),
                throughApiKey: $decision['through_api_key'],
            ), $payload['decisions']),
            agentReviews: array_map(static fn (array $review): DocumentAgentReviewResponse => new DocumentAgentReviewResponse(
                reviewId: $review['reviewId'],
                cardId: $review['cardId'],
                versionNumber: $review['versionNumber'],
                conclusion: $review['conclusion'],
                summary: $review['summary'],
                findings: array_map(static fn (array $finding): DocumentAgentReviewFindingResponse => new DocumentAgentReviewFindingResponse($finding['index'], $finding['severity'], $finding['title'], $finding['body'], $finding['needsPerson'], $finding['answer']), $review['findings']),
                answered: $review['answered'],
                answeredAt: $review['answeredAt'],
                createdAt: $review['createdAt'],
            ), $payload['agentReviews']),
            verdictThroughApiKey: $payload['verdictThroughApiKey'],
        );
    }
}
