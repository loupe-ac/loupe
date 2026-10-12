<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\PullRequestNotice;
use App\Module\Board\Service\PullRequestNoticeBody;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestState;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The comment that sums up one agent review on its pull request. It lists titles only: the bodies stay on the check.
 * The agent text comes from a worker that reads the pull request, so nothing in it may mention a person or open a link.
 */
final readonly class AgentReviewNoticeBody implements PullRequestNoticeBody
{
    private const string KEY_PREFIX = 'agent-review:';

    private const int MAX_SUMMARY = 4000;

    private const int MAX_TITLE = 200;

    public function __construct(
        private AgentReviewRepository $agentReviews,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    public static function key(Uuid $reviewId): string
    {
        return self::KEY_PREFIX.$reviewId->toRfc4122();
    }

    #[\Override]
    public function supports(string $noticeKey): bool
    {
        return str_starts_with($noticeKey, self::KEY_PREFIX);
    }

    #[\Override]
    public function stillHolds(PullRequestNotice $notice, ForgePullRequest $pullRequest): bool
    {
        return PullRequestState::Open === $pullRequest->state && null !== $this->reviewOf($notice);
    }

    #[\Override]
    public function of(PullRequestNotice $notice): string
    {
        $review = $this->reviewOf($notice) ?? throw new \LogicException('The review of the notice exists: '.$notice->noticeKey);

        $findings = $review->findings();
        // A stable sort keeps the order the agent gave within one severity.
        usort($findings, static fn (AgentReviewFinding $a, AgentReviewFinding $b): int => self::rank($a->severity) <=> self::rank($b->severity));
        $lines = [$this->verdict($review, $findings), '', $this->summary($review->summary), ''];
        foreach (\array_slice($findings, 0, AgentReviewCheckPublisher::MAX_LISTED_FINDINGS) as $finding) {
            $lines[] = $this->line($finding);
        }
        if (\count($findings) > AgentReviewCheckPublisher::MAX_LISTED_FINDINGS) {
            $lines[] = '- '.$this->trans('agent_review.comment.more', ['%count%' => \count($findings) - AgentReviewCheckPublisher::MAX_LISTED_FINDINGS]);
        }

        return implode("\n", $lines)."\n\n".$this->links($review);
    }

    private function reviewOf(PullRequestNotice $notice): ?AgentReview
    {
        if (!$this->supports($notice->noticeKey)) {
            return null;
        }
        $id = substr($notice->noticeKey, \strlen(self::KEY_PREFIX));

        return Uuid::isValid($id) ? $this->agentReviews->find(Uuid::fromString($id)) : null;
    }

    private static function rank(AgentReviewSeverity $severity): int
    {
        return (int) array_search($severity, AgentReviewSeverity::cases(), true);
    }

    /** @param list<AgentReviewFinding> $findings */
    private function verdict(AgentReview $review, array $findings): string
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }
        $parts = [$this->trans(AgentReviewConclusion::Success === $review->conclusion ? 'agent_review.comment.passed' : 'agent_review.comment.failed')];
        foreach (AgentReviewSeverity::cases() as $severity) {
            if (isset($counts[$severity->value])) {
                $parts[] = $this->trans('agent_review.check.count.'.$severity->value, ['%count%' => $counts[$severity->value]]);
            }
        }

        return $this->trans('agent_review.comment.verdict', ['%sha%' => mb_substr($review->headSha, 0, 7), '%result%' => implode(', ', $parts)]);
    }

    /** A zero-width space after each at sign stops a planted mention from notifying anyone. */
    private function summary(string $text): string
    {
        $text = str_replace('@', "@\u{200B}", $text);
        if (mb_strlen($text) <= self::MAX_SUMMARY) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_SUMMARY - 1).'…';
    }

    /** A code span keeps a title or a path from mentioning a user or opening a link. */
    private static function span(string $text): string
    {
        return '`'.trim(str_replace('`', '', preg_replace('/\s+/', ' ', $text) ?? '')).'`';
    }

    private function line(AgentReviewFinding $finding): string
    {
        if (null === $finding->path) {
            $place = $this->trans('agent_review.comment.spec');
        } else {
            $range = $finding->startLine === $finding->endLine ? (string) $finding->startLine : $finding->startLine.'-'.$finding->endLine;
            $place = self::span($finding->path.':'.$range);
        }

        return \sprintf('- %s · %s · %s', $finding->severity->value, $place, self::span(mb_strimwidth($finding->title, 0, self::MAX_TITLE, '...')));
    }

    private function links(AgentReview $review): string
    {
        $card = $this->urls->generate('app_board_card', [
            'projectId' => (string) $review->project->id,
            'cardId' => (string) $review->card->id,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
        $pullRequest = $review->pullRequest;
        if (null === $review->checkRunId || 'github' !== $pullRequest->forge) {
            return $this->trans('agent_review.comment.card_link', ['%card_url%' => $card]);
        }
        $check = \sprintf('https://github.com/%s/runs/%d', implode('/', array_map(rawurlencode(...), explode('/', $pullRequest->repository))), $review->checkRunId);

        return $this->trans('agent_review.comment.links', ['%check_url%' => $check, '%card_url%' => $card]);
    }

    /** @param array<string, int|string> $parameters */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'messages', $this->locale);
    }
}
