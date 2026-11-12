<?php

declare(strict_types=1);

namespace App\Module\Insights\Controller\Api;

use App\Module\Insights\Command\AnalysisDetailView;
use App\Module\Insights\Entity\Proposal;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/** One analysis with its cost and its proposals. */
#[OA\Schema(required: ['id', 'topic', 'scope', 'question', 'model', 'effort', 'state', 'reason', 'createdAt', 'finishedAt', 'documentId', 'costUsd', 'proposals'])]
final readonly class AnalysisResponse
{
    /** @param list<AnalysisProposalResponse> $proposals in the order the agent reported them */
    public function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,

        #[OA\Property(enum: ['cost', 'time', 'experiment', 'host', 'question'])]
        public string $topic,

        #[OA\Property(ref: new Model(type: AnalysisScopeResponse::class))]
        public AnalysisScopeResponse $scope,

        #[OA\Property(description: 'The question of a question analysis, else null.', nullable: true)]
        public ?string $question,

        #[OA\Property]
        public string $model,

        #[OA\Property]
        public string $effort,

        #[OA\Property(enum: ['waiting', 'running', 'done', 'failed', 'paused'])]
        public string $state,

        #[OA\Property(description: 'Why the analysis failed or paused, else null.', nullable: true)]
        public ?string $reason,

        #[OA\Property(format: 'date-time')]
        public string $createdAt,

        #[OA\Property(format: 'date-time', nullable: true)]
        public ?string $finishedAt,

        #[OA\Property(description: 'The report document once the analysis is done.', format: 'uuid', nullable: true)]
        public ?string $documentId,

        #[OA\Property(description: 'The cost in US dollars of the runs of this analysis so far. Null when no run has a known cost.', nullable: true)]
        public ?float $costUsd,

        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: AnalysisProposalResponse::class)))]
        public array $proposals,
    ) {
    }

    public static function fromView(AnalysisDetailView $view): self
    {
        $analysis = $view->analysis;
        $scope = $analysis->scope->toArray();

        return new self(
            id: (string) $analysis->id,
            topic: $analysis->topic->value,
            scope: new AnalysisScopeResponse($scope['range'], $scope['experiment'] ?? null),
            question: $analysis->question,
            model: $analysis->model,
            effort: $analysis->effort,
            state: $analysis->state->value,
            reason: $analysis->reason,
            createdAt: $analysis->createdAt->format(\DATE_ATOM),
            finishedAt: $analysis->finishedAt?->format(\DATE_ATOM),
            documentId: $analysis->documentId?->toRfc4122(),
            costUsd: null === $view->costMicroUsd ? null : $view->costMicroUsd / 1_000_000.0,
            proposals: array_map(static fn (Proposal $proposal): AnalysisProposalResponse => new AnalysisProposalResponse(
                id: (string) $proposal->id,
                kind: $proposal->kind->value,
                title: $proposal->title,
                body: $proposal->body,
                payload: $proposal->payload,
                estimatedSaving: $proposal->estimatedSaving,
                state: $proposal->state->value,
                dismissReason: $proposal->dismissReason,
                cardId: $proposal->cardId?->toRfc4122(),
            ), $view->proposals),
        );
    }
}
