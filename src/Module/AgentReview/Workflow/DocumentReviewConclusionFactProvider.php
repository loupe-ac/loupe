<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class DocumentReviewConclusionFactProvider implements FactProvider
{
    public function __construct(
        private DocumentAgentReviewRepository $documentAgentReviews,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return DocumentReviewConclusionFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $newest = $this->documentAgentReviews->findNewestConclusionByDocumentForCard($card->id);

        return new DocumentReviewConclusionFacts(
            array_map(static fn (array $review): AgentReviewConclusion => $review[0], $newest),
            array_map(static fn (array $review): int => $review[1], $newest),
        );
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof DocumentReviewConclusionFacts || throw new \LogicException('The provider fingerprints its own facts.');

        $conclusions = array_map(static fn (string $documentId, AgentReviewConclusion $conclusion): array => [$documentId, $conclusion->value, $facts->versionByDocument[$documentId] ?? null], array_keys($facts->newestByDocument), $facts->newestByDocument);

        return FingerprintValue::sorted($conclusions);
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.agent_review';
    }
}
