<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Inbox\Entity\InboxCardWaitType;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\View\CardWaitLine;
use App\Module\Inbox\View\WorkLimitStory;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\PauseReasonText;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Builds the translation key, the values and the link for one wait. The key is the label of the marker, and the values are its params. */
final readonly class CardWaitText
{
    /** The label that has a variant with the headline of the run output. */
    private const string RUN_LABEL = 'marker.run_needs_you';

    /** The label of a pause, which has variants that name the reason in words and the run that failed. */
    private const string PAUSE_LABEL = 'marker.paused';

    /** The variants of a work-limit pause, which name what looped. */
    private const string WORK_LIMIT_LABEL = 'marker.paused.work_limit';

    public function __construct(
        private DocumentRepository $documents,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkerRunRepository $workerRuns,
        private CardPauseRepository $cardPauses,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private PauseReasonText $pauseReasonText,
        private WorkLimitStories $workLimitStories,

        #[Autowire(param: 'kernel.default_locale')]
        private string $locale,
    ) {
    }

    /**
     * The title of the item: the reason of the wait that matters most, or the card title when no wait is open.
     * An open review wait matters most, so the title names the review that makes the item a Review.
     * A pause that failed work caused comes next, because it says why the card stopped and names the run.
     * The inbox row and the linked panel name the card, so the title leaves the card out.
     *
     * @param list<InboxCardWait> $open
     */
    public function title(array $open, string $cardTitle, int $maxLength): string
    {
        $main = $this->mainWait($open);
        $runTitle = null === $main ? null : $this->runTitle($main);
        if (null !== $runTitle) {
            return mb_substr($runTitle, 0, $maxLength);
        }
        $line = null === $main ? null : $this->line($main, false);
        $reason = null === $line ? '' : $this->translator->trans($line->titleKey(), $line->params, null, $this->locale);

        return mb_substr('' === $reason ? $cardTitle : $reason, 0, $maxLength);
    }

    /** The title that the worker of a run wait sent in its result, or null. */
    private function runTitle(InboxCardWait $wait): ?string
    {
        if (InboxCardWaitType::WorkerRun !== $wait->type || null === $wait->runId) {
            return null;
        }
        $run = $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $wait->watch->project->id);

        return null === $run ? null : RunSuggestions::title($run);
    }

    /**
     * One sentence for each wait the page shows, with its action, led by the wait the title names.
     *
     * @param list<InboxCardWait> $open
     */
    public function body(array $open): string
    {
        $lines = [];
        foreach ($this->inOrder($open) as $wait) {
            $line = $this->line($wait, false);
            $sentence = rtrim($this->translator->trans($line->key, $line->params, null, $this->locale), '.').'. '.$this->translator->trans($line->actionKey(), $line->params, null, $this->locale).'.';
            $lines[] = InboxCardWaitType::CardPause === $wait->type && null !== $line->detail ? $sentence.' '.$line->detail : $sentence;
        }

        return implode("\n", $lines);
    }

    /**
     * The open waits led by the one the title names, then the ended waits.
     *
     * @param iterable<InboxCardWait> $waits
     *
     * @return list<InboxCardWait>
     */
    public function inOrder(iterable $waits): array
    {
        $open = [];
        $ended = [];
        foreach ($waits as $wait) {
            if (null === $wait->endedAt) {
                $open[] = $wait;
            } else {
                $ended[] = $wait;
            }
        }
        $main = $this->mainWait($open);
        $rest = array_values(array_filter($open, static fn (InboxCardWait $wait): bool => $wait !== $main));

        return null === $main ? [...$open, ...$ended] : [$main, ...$rest, ...$ended];
    }

    /**
     * The waits the page shows, in page order. A main pause with no more to say than the title is left out.
     *
     * @param iterable<InboxCardWait> $waits
     *
     * @return list<InboxCardWait>
     */
    public function pageWaits(iterable $waits): array
    {
        $ordered = $this->inOrder($waits);
        $main = $ordered[0] ?? null;
        if (null === $main || null !== $main->endedAt || InboxCardWaitType::CardPause !== $main->type) {
            return $ordered;
        }
        $line = $this->line($main, false);

        return self::PAUSE_LABEL.'.reason' === $line->key && null === $line->detail ? \array_slice($ordered, 1) : $ordered;
    }

    /**
     * The first open wait about a document or a pull request.
     *
     * @param list<InboxCardWait> $open
     */
    public function reviewWait(array $open): ?InboxCardWait
    {
        foreach ($open as $wait) {
            if (null === $wait->endedAt && \in_array($wait->type, [InboxCardWaitType::Document, InboxCardWaitType::PullRequest], true)) {
                return $wait;
            }
        }

        return null;
    }

    /**
     * The item is a Review while a review wait is open, and Waiting otherwise.
     *
     * @param list<InboxCardWait> $open
     */
    public function kind(array $open): InboxItemKind
    {
        return null === $this->reviewWait($open) ? InboxItemKind::Wait : InboxItemKind::Review;
    }

    /**
     * @param list<InboxCardWait> $open
     */
    private function mainWait(array $open): ?InboxCardWait
    {
        return $this->reviewWait($open) ?? $this->failedWorkPauseWait($open) ?? self::openRunWait($open) ?? array_first($open);
    }

    /** @param list<InboxCardWait> $open */
    private function failedWorkPauseWait(array $open): ?InboxCardWait
    {
        foreach ($open as $wait) {
            if (null === $wait->endedAt && InboxCardWaitType::CardPause === $wait->type && null !== $wait->pauseId
                && \in_array($this->cardPauses->find($wait->pauseId)?->kind, [PauseKind::WorkStopped, PauseKind::Retries, PauseKind::WorkLimit], true)) {
                return $wait;
            }
        }

        return null;
    }

    /** @param list<InboxCardWait> $waits */
    private static function openRunWait(array $waits): ?InboxCardWait
    {
        foreach ($waits as $wait) {
            if (null === $wait->endedAt && InboxCardWaitType::WorkerRun === $wait->type) {
                return $wait;
            }
        }

        return null;
    }

    public function line(InboxCardWait $wait, bool $documentLinked): CardWaitLine
    {
        $projectId = $wait->watch->project->id;
        $params = ['%card%' => $wait->watch->cardNumber];
        $href = null;
        $detail = null;
        $key = '' === $wait->label ? 'inbox.wait.fallback.'.self::typePart($wait->type) : $wait->label;
        $titleKey = null;
        $pageKey = null;
        $action = 'inbox.wait.action.'.self::typePart($wait->type);

        switch ($wait->type) {
            case InboxCardWaitType::Document:
                $document = null === $wait->documentId ? null : $this->documents->find($wait->documentId);
                $params['%title%'] = $document->title ?? '';
                $params['%version%'] = $wait->versionNumber ?? 0;
                if (null !== $wait->documentId && null === $document) {
                    $key = 'review.inbox_wait.document_deleted';
                    $titleKey = $key.'.title';
                    $action = $key.'.action';
                    break;
                }
                if (null !== $wait->documentId) {
                    $titleKey = 'inbox.wait.review_title.document';
                }
                if ($documentLinked && null !== $wait->documentId) {
                    $href = $this->urls->generate('app_document_review', ['projectId' => $projectId, 'documentId' => $wait->documentId]);
                }
                break;
            case InboxCardWaitType::PullRequest:
                $pullRequest = null === $wait->pullRequestId ? null : $this->forgePullRequests->find($wait->pullRequestId);
                $params['%number%'] = $pullRequest->number ?? 0;
                $params['%pr_title%'] = $pullRequest->title ?? '';
                $href = null === $pullRequest ? null : \sprintf('https://github.com/%s/pull/%d', $pullRequest->repository, $pullRequest->number);
                break;
            case InboxCardWaitType::WorkerRun:
                $run = null === $wait->runId ? null : $this->workerRuns->findOneByIdAndProjectId($wait->runId->toRfc4122(), (string) $projectId);
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                $detail = null === $run ? null : RunOutputText::clean($run->output);
                $params = [...$params, ...RunOutputText::params($run->workKind ?? null, $run->output ?? '')];
                break;
            case InboxCardWaitType::CardPause:
            case InboxCardWaitType::Card:
                $href = $this->urls->generate('app_board_card', ['projectId' => $projectId, 'cardId' => $wait->watch->cardId]);
                break;
        }
        $params = [...$params, ...$wait->params];
        if (InboxCardWaitType::CardPause === $wait->type && self::PAUSE_LABEL === $key) {
            $story = $this->workLimitStory($wait);
            if (null !== $story) {
                [$key, $params, $detail] = $this->workLimitLine($wait, $story, $params);
                $titleKey = $key.'.title';
                $action = self::WORK_LIMIT_LABEL.'.action';
            } else {
                [$key, $params, $detail] = $this->pauseLine($wait, $params);
                $titleKey = self::PAUSE_LABEL.'.reason.title';
            }
        }
        if (InboxCardWaitType::PullRequest === $wait->type) {
            $titleKey = '' === ($params['%pr_title%'] ?? '') ? 'inbox.wait.review_title.pull_request_untitled' : 'inbox.wait.review_title.pull_request';
        }

        if (InboxCardWaitType::WorkerRun === $wait->type && self::RUN_LABEL === $key && '' !== ($params['%headline%'] ?? '')) {
            $pageKey = $key.'.short';
            $key .= '.headline';
            $titleKey = $key.'.title';
        }

        return new CardWaitLine($key, $params, $href, '' === $detail ? null : $detail, $titleKey ?? $key, $action, $pageKey);
    }

    /**
     * The reason of a pause in words and, for a pause that failed work caused, the run that failed last before it.
     *
     * @param array<string, string|int> $params
     *
     * @return array{string, array<string, string|int>, ?string}
     */
    private function pauseLine(InboxCardWait $wait, array $params): array
    {
        $pause = null === $wait->pauseId ? null : $this->cardPauses->find($wait->pauseId);
        $code = $pause->reason ?? (string) ($params['%reason%'] ?? '');
        $reason = '' === $code ? '' : $this->pauseReasonText->forPause($code);
        if ($reason === $code) {
            $reason = $this->translator->trans('inbox.wait.pause_code', ['%code%' => $code], null, $this->locale);
        }
        $params['%reason%'] = rtrim($reason, '.');

        $run = null === $pause ? null : $this->failedRun($wait, $pause);
        if (null === $run) {
            return [self::PAUSE_LABEL.'.reason', $params, null];
        }
        $params = [...$params, ...RunOutputText::params($run->workKind, '')];
        unset($params['%headline%']);
        $failure = trim($run->failureReason ?? '');
        $params['%failure%'] = rtrim($failure, '.');
        $params['%state%'] = mb_strtolower($this->translator->trans($run->state->translationKey(), [], null, $this->locale));
        $href = $this->urls->generate('app_project_worker_runs', ['id' => $wait->watch->project->id, 'search' => $run->id?->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);
        $link = \sprintf('[%s](%s)', $this->translator->trans('inbox.wait.pause_run_link', [], null, $this->locale), $href);

        return ['' === $failure ? self::PAUSE_LABEL.'.run_state' : self::PAUSE_LABEL.'.run', $params, $link];
    }

    /** The story of a work-limit pause, or null for another pause or when the template no longer names its rule. */
    public function workLimitStory(InboxCardWait $wait): ?WorkLimitStory
    {
        $pause = InboxCardWaitType::CardPause === $wait->type && null !== $wait->pauseId ? $this->cardPauses->find($wait->pauseId) : null;

        return null === $pause ? null : $this->workLimitStories->of($pause);
    }

    /**
     * What looped, in the title and the first sentence, and below it why: the review findings, the end states of the runs,
     * what the last stopped run said, the children an epic waits for, and the links.
     *
     * @param array<string, string|int> $params
     *
     * @return array{string, array<string, string|int>, ?string}
     */
    private function workLimitLine(InboxCardWait $wait, WorkLimitStory $story, array $params): array
    {
        $params['%count%'] = $story->limit;
        $params['%work%'] = $story->work();
        $params['%number%'] = $story->pullRequestNumber ?? 0;
        $variant = match (true) {
            null === $story->pullRequestNumber => 'card',
            'agent-review' === $story->reason => 'agent_review',
            default => 'pull_request',
        };
        $trans = fn (string $key, array $values = []): string => $this->translator->trans($key, $values, null, $this->locale);

        $parts = [];
        if ([] !== $story->findings) {
            $parts[] = $trans(self::WORK_LIMIT_LABEL.'.findings', ['%number%' => $params['%number%']])."\n\n".implode("\n", array_map(static fn (string $title): string => '- '.$title, $story->findings));
        }
        if ([] !== $story->runStates) {
            $states = [];
            foreach ($story->runStates as $state => $count) {
                $states[] = $count.' '.mb_strtolower($trans(WorkerRunState::from($state)->translationKey()));
            }
            $parts[] = $trans(self::WORK_LIMIT_LABEL.'.runs', ['%count%' => array_sum($story->runStates), '%work%' => $story->work(), '%states%' => implode(', ', $states)]);
        }
        $stop = $story->lastStop();
        if (null !== $stop) {
            $said = self::runSaid($stop);
            if ('' !== $said) {
                $parts[] = $trans(self::WORK_LIMIT_LABEL.'.last_stop', ['%work%' => $story->work(), '%state%' => mb_strtolower($trans($stop->state->translationKey()))])."\n\n".self::quote($said);
            }
        }
        if ($story->waitsForChildren()) {
            $cards = array_map(static fn (int $number): string => (string) $number, $story->openChildren);
            $last = array_pop($cards);
            $list = [] === $cards ? $last : implode(', ', $cards).' '.$trans('inbox.wait.and').' '.$last;
            $parts[] = $trans(null === $story->epicBranch ? self::WORK_LIMIT_LABEL.'.children' : self::WORK_LIMIT_LABEL.'.children_branch', ['%count%' => \count($story->openChildren), '%cards%' => $list, '%branch%' => $story->epicBranch ?? '']);
        }
        $links = [];
        if (null !== $story->pullRequestUrl) {
            $links[] = \sprintf('[%s](%s)', $trans(self::WORK_LIMIT_LABEL.'.pull_request_link', ['%number%' => $params['%number%']]), $story->pullRequestUrl);
        }
        if (null !== $story->reviewUrl) {
            $links[] = \sprintf('[%s](%s)', $trans(self::WORK_LIMIT_LABEL.'.review_link'), $story->reviewUrl);
        }
        $last = $story->runs[0] ?? null;
        if (null !== $last?->id) {
            $href = $this->urls->generate('app_project_worker_runs', ['id' => $wait->watch->project->id, 'search' => $last->id->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);
            $links[] = \sprintf('[%s](%s)', $trans(self::WORK_LIMIT_LABEL.'.run_link'), $href);
        }
        if ([] !== $links) {
            $parts[] = implode(' · ', $links);
        }

        return [self::WORK_LIMIT_LABEL.'.'.$variant, $params, [] === $parts ? null : implode("\n\n", $parts)];
    }

    /** The whole summary of a run, led by its title when the summary does not open with it. */
    private static function runSaid(WorkerRun $run): string
    {
        $summary = RunOutputText::clean($run->output);
        $title = RunSuggestions::title($run);
        if (null === $title || str_starts_with($summary, rtrim($title, '.'))) {
            return $summary;
        }

        return '' === $summary ? $title : $title."\n\n".$summary;
    }

    private static function quote(string $text): string
    {
        return implode("\n", array_map(static fn (string $line): string => rtrim('> '.$line), preg_split('/\R/u', $text) ?: [$text]));
    }

    /** The run that ended last before the pause, when it failed, for a pause that a failure of work caused. */
    private function failedRun(InboxCardWait $wait, CardPause $pause): ?WorkerRun
    {
        // A refused action, such as a move, also pauses with the kind retries, and no run caused it.
        if (!\in_array($pause->kind, [PauseKind::Retries, PauseKind::WorkStopped], true) || $this->pauseReasonText->forRefusal($pause->reason) !== $pause->reason) {
            return null;
        }

        $run = $this->workerRuns->findLatestEndedOfCard($wait->watch->project, $wait->watch->cardId, $pause->createdAt, $pause->ruleId);

        return true === $run?->state->isFailure() ? $run : null;
    }

    private static function typePart(InboxCardWaitType $type): string
    {
        return str_replace('-', '_', $type->value);
    }
}
