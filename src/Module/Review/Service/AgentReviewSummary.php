<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;

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
    /** The number of failed reviews after which the loop gives up. */
    public const int FAILED_ROUND_LIMIT = 3;

    public function __construct(
        private DocumentAgentReviews $agentReviews,
    ) {
    }

    /** @return ?Summary null when the page shows no summary */
    public function forDocument(Document $document): ?array
    {
        return $this->summarize($this->agentReviews->forDocument($document), $document->status);
    }

    /**
     * @param list<DocumentAgentReviewSummary> $newestFirst
     *
     * @return ?Summary
     */
    public function summarize(array $newestFirst, DocumentStatus $status): ?array
    {
        if ([] === $newestFirst) {
            return null;
        }

        $failed = \count(array_filter($newestFirst, static fn (array $review): bool => 'success' !== $review['conclusion']));
        $state = match (true) {
            'success' === $newestFirst[0]['conclusion'] => 'passed',
            $failed >= self::FAILED_ROUND_LIMIT => 'failed',
            DocumentStatus::Draft !== $status => 'skipped',
            default => null,
        };
        if (null === $state) {
            return null;
        }

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
