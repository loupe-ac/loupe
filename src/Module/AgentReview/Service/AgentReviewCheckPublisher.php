<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewPost;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\AgentReviewCheck;
use App\Module\Board\Service\SiteReviewWriteResult;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Posts each check of the card that AgentReviewPosts finds due: a review on its own head, or a carried review on a later head.
 *
 * Each review gets a new run: the forge appends the notes of every update to a run and cannot remove them.
 * For the same reason, a run that took only part of the notes keeps its id, and the retry sends it the rest.
 */
#[AsAlias(AgentReviewCheck::class)]
final readonly class AgentReviewCheckPublisher implements AgentReviewCheck
{
    public const string NAME = 'loupe/agent-review';

    public const int MAX_LISTED_FINDINGS = 20;

    private const int MAX_BODY_WIDTH = 300;

    public function __construct(
        private AgentReviewPosts $posts,
        private PullRequestCheckWriters $writers,
        private AgentReviewAnnotations $annotations,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** A failure on one check does not hold back the others. */
    #[\Override]
    public function publish(Card $card): SiteReviewWriteResult
    {
        $failure = null;
        $changed = false;
        foreach ($this->posts->dueForCard($card) as $due) {
            $review = $due->review;
            $writer = $this->writers->for($due->pullRequest->forge);
            if (null === $writer) {
                continue;
            }
            $target = $review;
            $conclusion = $due->conclusion();
            $summary = $this->summary($review);
            $annotations = $this->annotations->of($review);
            if ($due->carried) {
                $target = $due->post ?? $this->newPost($due);
                $summary = $this->translator->trans('agent_review.check.carried', ['%sha%' => mb_substr($review->headSha, 0, 7)])."\n\n".$summary;
                if (!$target->lineNotes) {
                    $annotations = [];
                }
            }
            if (null !== $due->approver) {
                $summary = $this->translator->trans('agent_review.check.overridden', ['%login%' => $due->approver])."\n\n".$summary;
            }
            try {
                $runId = $writer->publish(
                    $due->pullRequest,
                    self::NAME,
                    $due->headSha,
                    $conclusion,
                    $this->title($review),
                    $summary,
                    $target->checkRunId,
                    \array_slice($annotations, $target->annotationsPosted),
                );
            } catch (PullRequestCheckFailed $e) {
                // A project with no GitHub App installation has nothing to post a check with.
                if ('no_installation' !== $e->cause) {
                    $failure ??= $e->cause;
                }
                if (null !== $e->runId) {
                    $target->checkRunId = $e->runId;
                    $target->annotationsPosted += $e->annotationsSent;
                    $this->em->flush();
                }

                continue;
            }

            $target->checkRunId = $runId;
            $target->checkConclusion = $conclusion;
            $target->annotationsPosted = \count($annotations);
            $target->postedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->em->flush();
            $changed = true;
        }

        return new SiteReviewWriteResult($failure, $changed);
    }

    /** The line notes of a carried check point at lines of the reviewed head, so they go out only when no changed line moved. */
    private function newPost(DuePost $due): AgentReviewPost
    {
        $pullRequest = $due->pullRequest;
        $current = null !== $pullRequest->changeFingerprintSha && mb_strtolower($pullRequest->changeFingerprintSha) === mb_strtolower($due->headSha);
        $linesKept = $current && null !== $due->review->linesFingerprint && $due->review->linesFingerprint === $pullRequest->linesFingerprint;

        $post = new AgentReviewPost($due->review, $due->headSha, $linesKept);
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    private function title(AgentReview $review): string
    {
        $counts = [];
        foreach ($review->findings() as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        $parts = [];
        foreach (AgentReviewSeverity::cases() as $severity) {
            if (isset($counts[$severity->value])) {
                $parts[] = $this->translator->trans('agent_review.check.count.'.$severity->value, ['%count%' => $counts[$severity->value]]);
            }
        }

        return [] === $parts
            ? $this->translator->trans('agent_review.check.title_clear')
            : $this->translator->trans('agent_review.check.title', ['%findings%' => implode(', ', $parts)]);
    }

    private function summary(AgentReview $review): string
    {
        $lines = [$review->summary];
        $findings = $review->findings();
        usort($findings, static fn (AgentReviewFinding $a, AgentReviewFinding $b): int => (AgentReviewCategory::Spec === $b->category) <=> (AgentReviewCategory::Spec === $a->category));
        $listed = \array_slice($findings, 0, self::MAX_LISTED_FINDINGS);
        $headed = array_any($findings, static fn (AgentReviewFinding $finding): bool => AgentReviewCategory::Spec === $finding->category);
        $heading = null;
        foreach ($listed as $finding) {
            if ($finding->category !== $heading) {
                $heading = $finding->category;
                $lines[] = '';
                if ($headed) {
                    $lines[] = $this->translator->trans('agent_review.check.heading.'.$finding->category->value);
                }
            }
            $lines[] = $this->line($finding);
        }
        if (\count($findings) > self::MAX_LISTED_FINDINGS) {
            $lines[] = $this->translator->trans('agent_review.check.summary_more', ['%count%' => \count($findings) - self::MAX_LISTED_FINDINGS]);
        }

        return implode("\n", $lines);
    }

    private function line(AgentReviewFinding $finding): string
    {
        $place = '';
        if (null !== $finding->path) {
            $range = $finding->startLine === $finding->endLine ? (string) $finding->startLine : $finding->startLine.'-'.$finding->endLine;
            $place = $finding->path.':'.$range.' ';
        }
        $mark = $finding->needsPerson ? $this->translator->trans('agent_review.check.needs_person').' ' : '';
        $line = \sprintf('- [%s] %s%s%s', $finding->severity->value, $mark, $place, $finding->title);
        $body = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $finding->body) ?? ''), 0, self::MAX_BODY_WIDTH, '...');

        return '' === $body ? $line : $line.': '.$body;
    }
}
