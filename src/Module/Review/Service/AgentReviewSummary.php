<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Contract\DesignReviewRoundLimit;

/**
 * Turns the agent reviews of a document into what its page shows: a state, the counts
 * of the summary line and the rounds. The state is derived from the reviews and the
 * document status, never stored.
 *
 * @phpstan-import-type DocumentAgentReviewSummary from DocumentAgentReviews
 *
 * @phpstan-type SummaryFinding array{severity: string, title: string, body: string, status: 'fixed'|'not-changed'|'to-owner'|'open', reason: ?string, fixedInVersion: ?int}
 * @phpstan-type SummaryRound array{number: int, version: int, passed: bool, importantCount: int, findingCount: int, fixedCount: int, fixedInVersion: ?int, listed: bool, findings: list<SummaryFinding>}
 * @phpstan-type Summary array{state: 'passed'|'failed'|'skipped', rounds: int, fixed: int, notChanged: int, open: array<string, int>, roundList: list<SummaryRound>}
 */
final readonly class AgentReviewSummary
{
    public function __construct(
        private DocumentAgentReviews $agentReviews,
        private DesignReviewRoundLimit $roundLimit,
    ) {
    }

    /** @return ?Summary null when the page shows no summary */
    public function forDocument(Document $document): ?array
    {
        $reviews = $this->agentReviews->forDocument($document);
        if ([] === $reviews || DocumentStatus::Draft === $document->status) {
            return null;
        }

        $tags = $document->tags->map(static fn (Tag $tag): string => $tag->name)->getValues();

        return $this->summarize($reviews, $document->status, $this->roundLimit->of($document->project->id ?? throw new \LogicException('A reviewed document belongs to a saved project.'), $tags));
    }

    /**
     * @param list<DocumentAgentReviewSummary> $newestFirst
     * @param ?int                             $roundLimit  the failed reviews, and passes with a finding for the owner, after which the loop gives up, or null when the workflow has no limit
     *
     * @return ?Summary
     */
    public function summarize(array $newestFirst, DocumentStatus $status, ?int $roundLimit): ?array
    {
        if ([] === $newestFirst || DocumentStatus::Draft === $status) {
            return null;
        }

        $failed = \count(array_filter($newestFirst, static fn (array $review): bool => $review['cardId'] === $newestFirst[0]['cardId'] && ('success' !== $review['conclusion'] || self::hasOwnerFinding($review))));
        $state = match (true) {
            $this->loopPassed($newestFirst[0]) => 'passed',
            null !== $roundLimit && $failed >= $roundLimit => 'failed',
            default => 'skipped',
        };

        $oldestFirst = array_reverse($newestFirst);
        $roundList = [];
        $fixed = 0;
        $notChanged = 0;
        $open = [];
        foreach ($oldestFirst as $position => $review) {
            $latest = $position === \count($oldestFirst) - 1;
            $fixedInVersion = $oldestFirst[$position + 1]['versionNumber'] ?? null;
            $findings = [];
            $roundFixed = 0;
            $important = 0;
            $needsDetail = false;
            foreach ($review['findings'] as $finding) {
                $answer = $finding['answer']['answer'] ?? null;
                $findingStatus = match ($answer) {
                    'fixed' => 'fixed',
                    'not-changed' => 'not-changed',
                    'to-owner' => 'to-owner',
                    default => 'open',
                };
                if ('important' === $finding['severity']) {
                    ++$important;
                }
                if ('fixed' === $findingStatus) {
                    ++$roundFixed;
                    ++$fixed;
                } else {
                    $needsDetail = true;
                }
                if ('not-changed' === $findingStatus) {
                    ++$notChanged;
                }
                if ($latest && \in_array($findingStatus, ['open', 'to-owner'], true)) {
                    $open[$finding['severity']] = ($open[$finding['severity']] ?? 0) + 1;
                }
                $findings[] = [
                    'severity' => $finding['severity'],
                    'title' => $finding['title'],
                    'body' => $finding['body'],
                    'status' => $findingStatus,
                    'reason' => $finding['answer']['reason'] ?? null,
                    'fixedInVersion' => $fixedInVersion,
                ];
            }
            array_unshift($roundList, [
                'number' => $position + 1,
                'version' => $review['versionNumber'],
                'passed' => 'success' === $review['conclusion'],
                'importantCount' => $important,
                'findingCount' => \count($findings),
                'fixedCount' => $roundFixed,
                'fixedInVersion' => $fixedInVersion,
                'listed' => $latest || $needsDetail,
                'findings' => $findings,
            ]);
        }

        return [
            'state' => $state,
            'rounds' => \count($oldestFirst),
            'fixed' => $fixed,
            'notChanged' => $notChanged,
            'open' => $this->orderedBySeverity($open),
            'roundList' => $roundList,
        ];
    }

    /**
     * A passed review with a finding for the owner ends the loop only once its fix round
     * answers. A publish before that answer skipped the loop.
     *
     * @param DocumentAgentReviewSummary $review
     */
    private function loopPassed(array $review): bool
    {
        if ('success' !== $review['conclusion']) {
            return false;
        }

        return $review['answered'] || !self::hasOwnerFinding($review);
    }

    /** @param DocumentAgentReviewSummary $review */
    private static function hasOwnerFinding(array $review): bool
    {
        return [] !== array_filter($review['findings'], static fn (array $finding): bool => $finding['needsPerson']);
    }

    /**
     * @param array<string, int> $open
     *
     * @return array<string, int>
     */
    private function orderedBySeverity(array $open): array
    {
        $ordered = [];
        foreach (['important', 'nit', 'pre-existing'] as $severity) {
            if (isset($open[$severity])) {
                $ordered[$severity] = $open[$severity];
            }
        }

        return $ordered;
    }
}
