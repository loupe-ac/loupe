<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Workflow;

use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\DesignReviewTags;
use App\Module\Workflow\Contract\FactProvider;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class DocumentReviewFactProvider implements FactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
        private DocumentRepository $documents,
        private DocumentVersionRepository $documentVersions,
        private DocumentAgentReviewRepository $documentAgentReviews,
        private DesignReviewTags $designReviewTags,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return DocumentReviewFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return true;
    }

    #[\Override]
    public function build(CardSnapshot $snapshot): object
    {
        $card = $this->cards->find($snapshot->id) ?? throw new \LogicException('The card of the facts exists.');

        $drafts = [];
        $tags = [];
        $reviewedTags = $this->designReviewTags->of($card->project->requireId());
        foreach ($this->cardDocuments->findStatusesAndTagsForCard($card) as $row) {
            $designTags = array_values(array_intersect($row['tags'], $reviewedTags));
            $document = DocumentStatus::Draft->value === $row['status'] && [] !== $designTags ? $this->documents->find($row['id']) : null;
            if ($document instanceof Document) {
                $drafts[] = $document;
                $tags[$row['id']] = $row['tags'];
            }
        }
        if ([] === $drafts) {
            return new DocumentReviewFacts([]);
        }

        $versions = $this->documentVersions->findLatestMetaByDocuments($drafts);
        $counts = $this->documentAgentReviews->countRoundsByDocumentForCard($card);

        $states = [];
        foreach ($drafts as $document) {
            $id = (string) $document->id;
            $review = $this->documentAgentReviews->findLatestOfDocumentForCard($document, $card);
            $states[] = new DocumentReviewState(
                $id,
                $tags[$id],
                ($versions[$id] ?? null)['versionNumber'] ?? 0,
                null === $review ? null : (string) $review->id,
                $review?->versionNumber,
                $review?->conclusion,
                $review?->isAnswered() ?? false,
                null !== $review && [] !== array_filter($review->findings(), static fn ($finding): bool => $finding->needsPerson),
                $counts[$id]['failed'] ?? 0,
                $counts[$id]['ownerPasses'] ?? 0,
            );
        }

        return new DocumentReviewFacts($states);
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof DocumentReviewFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::sorted(array_map(
            static fn (DocumentReviewState $document): array => [$document->documentId, FingerprintValue::sorted($document->tags), $document->versionNumber, $document->reviewId, $document->answered, $document->failedCount, $document->ownerPassCount],
            $facts->documents,
        ));
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.agent_review';
    }
}
