<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Repository\DocumentAgentReviewRepository;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DraftReviewLoop;
use App\Module\Review\Service\DraftReviewLoops;
use App\Module\Workflow\Contract\DesignReviewRoundLimit;
use App\Module\Workflow\Contract\DesignReviewTags;
use App\Module\Workflow\Contract\PauseReasonText;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** Reads the loop from the stored reviews and the template, as the workflow does. */
#[AsAlias(DraftReviewLoops::class)]
final readonly class DraftReviewLoopReader implements DraftReviewLoops
{
    public function __construct(
        private DesignReviewTags $designReviewTags,
        private DesignReviewRoundLimit $roundLimit,
        private CardDocumentRepository $cardDocuments,
        private DocumentAgentReviewRepository $documentAgentReviews,
        private CardPauseRepository $cardPauses,
        private PauseReasonText $pauseReasonText,
    ) {
    }

    #[\Override]
    public function of(Document $document): ?DraftReviewLoop
    {
        if (DocumentStatus::Draft !== $document->status) {
            return null;
        }

        $projectId = $document->project->requireId();
        $tags = array_values(array_intersect(
            $document->tags->map(static fn (Tag $tag): string => $tag->name)->toArray(),
            $this->designReviewTags->of($projectId),
        ));
        if ([] === $tags) {
            return null;
        }

        $limit = $this->roundLimit->of($projectId, $tags);
        if (null === $limit) {
            return null;
        }

        $running = [];
        $paused = [];
        foreach ($this->cardDocuments->findForDocument($document) as $link) {
            $card = $link->card;
            if ($card->column->terminal || [] === array_intersect($tags, $this->designReviewTags->reviewedInColumn($projectId, (string) $card->column->id))) {
                continue;
            }

            $pause = $this->cardPauses->findActiveForCard($card);
            $counts = $this->documentAgentReviews->countRoundsByDocumentForCard($card)[(string) $document->id] ?? ['failed' => 0, 'ownerPasses' => 0];
            $loop = new DraftReviewLoop(
                min($counts['failed'] + $counts['ownerPasses'] + 1, $limit),
                $limit,
                null === $pause ? null : $this->pauseReasonText->forPause($pause->reason),
                (string) $card->id,
                $card->number,
            );
            if ($loop->paused()) {
                $paused[$card->number] = $loop;
            } else {
                $running[$card->number] = $loop;
            }
        }

        ksort($running);
        ksort($paused);

        return array_first($running) ?? array_first($paused);
    }
}
